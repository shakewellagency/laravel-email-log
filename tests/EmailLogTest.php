<?php

namespace Shakewell\EmailLog\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Shakewell\EmailLog\EmailLogServiceProvider;
use Shakewell\EmailLog\Models\SentEmail;

class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [EmailLogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('mail.default', 'array');
    }

    public function test_a_sent_email_is_recorded(): void
    {
        Mail::raw('Body text', function (Message $message) {
            $message->from('noreply@example.com', 'App')
                ->to('jane@example.com', 'Jane Doe')
                ->cc('ops@example.com')
                ->subject('Service request update');
        });

        $email = SentEmail::sole();
        $this->assertSame('App <noreply@example.com>', $email->from);
        $this->assertSame('Jane Doe <jane@example.com>', $email->to);
        $this->assertSame('ops@example.com', $email->cc);
        $this->assertNull($email->bcc);
        $this->assertSame('Service request update', $email->subject);
        $this->assertSame('Body text', $email->body);
        $this->assertNotNull($email->message_id);
    }

    public function test_the_mailable_class_and_the_ses_message_id_are_recorded(): void
    {
        Mail::to('jane@example.com')->send(new TestMailable);

        $email = SentEmail::sole();
        // Laravel 10's Mailable does not pass its class to the mail events.
        $this->assertSame(version_compare($this->app->version(), '11', '>=') ? TestMailable::class : null, $email->mailable);
        $this->assertSame('0100-ses-message-id', $email->message_id);
    }

    public function test_a_long_subject_is_truncated_to_fit_the_column(): void
    {
        Mail::raw('Body', fn (Message $message) => $message->to('jane@example.com')->subject(str_repeat('a', 300)));

        $this->assertSame(255, strlen(SentEmail::sole()->subject));
    }

    public function test_nothing_is_recorded_when_disabled(): void
    {
        config(['email-log.enabled' => false]);

        Mail::raw('Body', fn (Message $message) => $message->to('jane@example.com')->subject('Hi'));

        $this->assertSame(0, SentEmail::count());
    }

    public function test_a_logging_failure_does_not_fail_the_send(): void
    {
        Schema::drop('sent_emails');

        Mail::raw('Body', fn (Message $message) => $message->to('jane@example.com')->subject('Hi'));

        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_records_older_than_the_retention_period_are_pruned(): void
    {
        config(['email-log.retention_days' => 30]);
        SentEmail::create(['body' => 'old', 'created_at' => now()->subDays(31)]);
        SentEmail::create(['body' => 'new', 'created_at' => now()->subDays(29)]);

        Artisan::call('model:prune', ['--model' => [SentEmail::class]]);

        $this->assertSame(['new'], SentEmail::pluck('body')->all());
    }

    public function test_nothing_is_pruned_without_a_retention_period(): void
    {
        SentEmail::create(['body' => 'old', 'created_at' => now()->subYears(5)]);

        Artisan::call('model:prune', ['--model' => [SentEmail::class]]);

        $this->assertSame(1, SentEmail::count());
    }

    public function test_an_existing_dcblogdev_table_is_upgraded_in_place(): void
    {
        Schema::drop('sent_emails');
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->increments('id');
            $table->date('date')->nullable();
            $table->string('from')->nullable();
            $table->text('to')->nullable();
            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->timestamps();
        });
        DB::table('sent_emails')->insert(['to' => 'old@example.com', 'body' => 'kept']);

        (require __DIR__.'/../database/migrations/2026_09_26_000000_create_or_upgrade_sent_emails_table.php')->up();

        $this->assertTrue(Schema::hasColumns('sent_emails', ['message_id', 'mailable']));
        $this->assertSame('kept', DB::table('sent_emails')->value('body'));
    }
}

class TestMailable extends Mailable
{
    public function build(): static
    {
        return $this->subject('Hello')
            ->html('<p>Hello</p>')
            ->withSymfonyMessage(fn ($message) => $message->getHeaders()->addTextHeader('X-SES-Message-ID', '0100-ses-message-id'));
    }
}
