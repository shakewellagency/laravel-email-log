<?php

namespace Shakewell\EmailLog;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Shakewell\EmailLog\Listeners\LogSentEmail;

class EmailLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email-log.php', 'email-log');
    }

    public function boot(): void
    {
        Event::listen(MessageSent::class, LogSentEmail::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/email-log.php' => config_path('email-log.php'),
        ], 'email-log-config');
    }
}
