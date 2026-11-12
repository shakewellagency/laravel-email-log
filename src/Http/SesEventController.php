<?php

namespace Shakewell\EmailLog\Http;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shakewell\EmailLog\Models\SentEmailEvent;
use Throwable;

/**
 * Receives the SES events an SES configuration set publishes to SNS and stores them in sent_email_events.
 *
 * Unauthenticated by design, since SNS cannot hold a token: every request must come from the configured topic and
 * carry a valid AWS SNS signature, or it is rejected.
 */
class SesEventController
{
    public function __invoke(Request $request): Response
    {
        $topic = config('email-log.ses_events.topic_arn');

        try {
            $message = Message::fromJsonString($request->getContent());
        } catch (Throwable $e) {
            Log::warning('Rejected SES event notification', ['reason' => $e->getMessage()]);

            return response()->noContent(403);
        }

        // Checked before the signature so a junk request never makes us fetch a certificate. TopicArn is part of
        // the signed string, so validate() below authenticates it.
        if (! $topic || ! hash_equals($topic, (string) $message['TopicArn'])) {
            Log::warning('Rejected SES event notification from another topic', ['topic' => $message['TopicArn']]);

            return response()->noContent(403);
        }

        try {
            // SNS rotates its signing certificate rarely; one download a day per URL is plenty.
            (new MessageValidator(fn (string $url) => Cache::remember(
                'email-log:sns-cert:'.sha1($url),
                now()->addDay(),
                fn () => Http::timeout(5)->get($url)->throw()->body(),
            )))->validate($message);
        } catch (Throwable $e) {
            Log::warning('Rejected SES event notification', ['reason' => $e->getMessage()]);

            return response()->noContent(403);
        }

        match ($message['Type']) {
            'SubscriptionConfirmation' => Http::timeout(5)->get($message['SubscribeURL'])->throw(),
            'Notification' => $this->record($message),
            default => null,
        };

        return response()->noContent();
    }

    private function record(Message $message): void
    {
        $event = json_decode($message['Message'], true);
        // Event publishing sends eventType; the older identity notifications send notificationType.
        $type = $event['eventType'] ?? $event['notificationType'] ?? null;
        $messageId = $event['mail']['messageId'] ?? null;

        if (! $type || ! $messageId) {
            Log::warning('SES event notification without an event type or message id', ['sns_message_id' => $message['MessageId']]);

            return;
        }

        // Each event type carries its details under its own key, e.g. "bounce" for Bounce.
        $detail = $event[$type === 'Rendering Failure' ? 'failure' : lcfirst($type)] ?? null;
        $affected = $detail['bouncedRecipients'] ?? $detail['complainedRecipients'] ?? $detail['delayedRecipients'] ?? null;
        $now = now();

        // insertOrIgnore on the unique sns_message_id: a redelivered notification is stored once.
        SentEmailEvent::query()->insertOrIgnore([
            'message_id' => $messageId,
            'sns_message_id' => $message['MessageId'],
            'event_type' => $type,
            'recipients' => json_encode($detail['recipients']
                ?? ($affected !== null ? array_column($affected, 'emailAddress') : ($event['mail']['destination'] ?? null))),
            'detail' => json_encode($detail),
            // insertOrIgnore skips casts, so convert SES's UTC time to the app timezone the datetime cast reads back in.
            'occurred_at' => Carbon::parse($detail['timestamp'] ?? $event['mail']['timestamp'] ?? $message['Timestamp'])
                ->setTimezone(config('app.timezone')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
