<?php

namespace Shakewell\EmailLog\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Shakewell\EmailLog\Models\SentEmail;
use Symfony\Component\Mime\Address;
use Throwable;

class LogSentEmail
{
    /**
     * Runs after the mail transport accepted the message, so only mail that actually went out is recorded.
     */
    public function handle(MessageSent $event): void
    {
        if (! config('email-log.enabled')) {
            return;
        }

        // The email has already been sent: a logging failure must not surface as a failed send.
        try {
            $message = $event->message;
            $headers = $message->getHeaders();

            SentEmail::create([
                'date' => now(),
                'from' => $this->addresses($message->getFrom()),
                'to' => $this->addresses($message->getTo()),
                'cc' => $this->addresses($message->getCc()),
                'bcc' => $this->addresses($message->getBcc()),
                'subject' => Str::limit((string) $message->getSubject(), 252),
                'body' => (string) ($message->getHtmlBody() ?? $message->getTextBody() ?? ''),
                'message_id' => $headers->get('X-SES-Message-ID')?->getBodyAsString() ?? $event->sent->getMessageId(),
                'mailable' => $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Address[]  $addresses
     */
    private function addresses(array $addresses): ?string
    {
        // Same "Name <address>" format dcblogdev/laravel-sent-emails wrote, so existing lookups keep matching.
        return $addresses === [] ? null : implode(', ', array_map(
            fn (Address $address) => $address->getName() === '' ? $address->getAddress() : "{$address->getName()} <{$address->getAddress()}>",
            $addresses,
        ));
    }
}
