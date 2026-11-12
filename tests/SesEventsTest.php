<?php

namespace Shakewell\EmailLog\Tests;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Shakewell\EmailLog\EmailLogServiceProvider;
use Shakewell\EmailLog\Models\SentEmail;
use Shakewell\EmailLog\Models\SentEmailEvent;

/**
 * Messages are signed with a throwaway certificate served from a faked SNS certificate URL, so the real signature
 * check runs, not a stub.
 */
class SesEventsTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC = 'arn:aws:sns:us-east-1:123456789012:app-test-ses-events';

    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem';

    private $key;

    protected function getPackageProviders($app): array
    {
        return [EmailLogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('email-log.ses_events.topic_arn', self::TOPIC);
        $app['config']->set('email-log.ses_events.configuration_set', 'app-test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $this->key), null, $this->key, 1);
        openssl_x509_export($cert, $pem);

        Http::fake([
            self::CERT_URL => Http::response($pem),
            'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription*' => Http::response('<ConfirmSubscriptionResponse/>'),
        ]);
    }

    private function sns(array $fields): string
    {
        $fields += [
            'MessageId' => (string) Str::uuid(),
            'TopicArn' => self::TOPIC,
            'Timestamp' => '2026-11-12T10:00:00.000Z',
            'SignatureVersion' => '2',
            'SigningCertURL' => self::CERT_URL,
            'Signature' => 'placeholder',
        ];
        openssl_sign((new MessageValidator)->getStringToSign(new Message($fields)), $signature, $this->key, OPENSSL_ALGO_SHA256);
        $fields['Signature'] = base64_encode($signature);

        return json_encode($fields);
    }

    private function notification(array $event, array $fields = []): string
    {
        return $this->sns($fields + ['Type' => 'Notification', 'Message' => json_encode($event)]);
    }

    private function postSns(string $body)
    {
        return $this->call('POST', '/email-log/ses-events', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_a_delivery_event_is_stored_against_the_sent_email(): void
    {
        $email = SentEmail::create(['body' => 'x', 'message_id' => 'ses-1', 'subject' => 'Status changed']);

        $this->postSns($this->notification([
            'eventType' => 'Delivery',
            'mail' => ['messageId' => 'ses-1', 'destination' => ['a@example.com']],
            'delivery' => ['timestamp' => '2026-11-12T09:59:59.000Z', 'recipients' => ['a@example.com'], 'smtpResponse' => '250 OK'],
        ]))->assertNoContent();

        $event = $email->events()->sole();
        $this->assertSame('Delivery', $event->event_type);
        $this->assertSame(['a@example.com'], $event->recipients);
        $this->assertSame('250 OK', $event->detail['smtpResponse']);
        $this->assertSame('2026-11-12T09:59:59+00:00', $event->occurred_at->toIso8601String());
    }

    public function test_a_bounce_records_the_bounced_recipients_and_a_send_falls_back_to_the_destination(): void
    {
        $this->postSns($this->notification([
            'eventType' => 'Bounce',
            'mail' => ['messageId' => 'ses-2', 'destination' => ['a@example.com', 'b@example.com']],
            'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'b@example.com']]],
        ]))->assertNoContent();
        $this->postSns($this->notification([
            'eventType' => 'Send',
            'mail' => ['messageId' => 'ses-2', 'timestamp' => '2026-11-12T09:59:00.000Z', 'destination' => ['a@example.com', 'b@example.com']],
            'send' => [],
        ]))->assertNoContent();

        $this->assertSame(['b@example.com'], SentEmailEvent::where('event_type', 'Bounce')->sole()->recipients);
        $this->assertSame(['a@example.com', 'b@example.com'], SentEmailEvent::where('event_type', 'Send')->sole()->recipients);
    }

    public function test_a_redelivered_notification_is_stored_once(): void
    {
        $body = $this->notification(['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-3'], 'delivery' => ['recipients' => ['a@example.com']]]);

        $this->postSns($body)->assertNoContent();
        $this->postSns($body)->assertNoContent();

        $this->assertSame(1, SentEmailEvent::count());
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        $body = json_decode($this->notification(['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-4']]), true);
        $body['Message'] = json_encode(['eventType' => 'Delivery', 'mail' => ['messageId' => 'tampered']]);

        $this->postSns(json_encode($body))->assertForbidden();

        $this->assertSame(0, SentEmailEvent::count());
    }

    public function test_another_topic_is_rejected_without_fetching_a_certificate(): void
    {
        $this->postSns($this->notification(
            ['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-5']],
            ['TopicArn' => 'arn:aws:sns:us-east-1:999999999999:someone-elses-topic'],
        ))->assertForbidden();

        $this->assertSame(0, SentEmailEvent::count());
        Http::assertNothingSent();
    }

    public function test_the_signing_certificate_is_downloaded_once(): void
    {
        $this->postSns($this->notification(['eventType' => 'Send', 'mail' => ['messageId' => 'ses-6'], 'send' => []]))->assertNoContent();
        $this->postSns($this->notification(['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-6'], 'delivery' => []]))->assertNoContent();

        Http::assertSentCount(1);
    }

    public function test_a_subscription_confirmation_is_confirmed(): void
    {
        $url = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.self::TOPIC.'&Token=abc';

        $this->postSns($this->sns([
            'Type' => 'SubscriptionConfirmation',
            'Message' => 'You have chosen to subscribe to the topic.',
            'Token' => 'abc',
            'SubscribeURL' => $url,
        ]))->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === $url);
    }

    public function test_the_event_time_is_right_when_the_app_is_not_on_utc(): void
    {
        config(['app.timezone' => 'Australia/Sydney']);
        date_default_timezone_set('Australia/Sydney');

        try {
            $this->postSns($this->notification([
                'eventType' => 'Delivery',
                'mail' => ['messageId' => 'ses-7'],
                'delivery' => ['timestamp' => '2026-11-12T09:59:59.000Z', 'recipients' => ['a@example.com']],
            ]))->assertNoContent();

            $this->assertTrue(SentEmailEvent::sole()->occurred_at->equalTo('2026-11-12T09:59:59Z'));
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_the_configuration_set_is_applied_to_the_ses_mailer(): void
    {
        $this->assertSame('app-test', config('services.ses.options.ConfigurationSetName'));
    }
}
