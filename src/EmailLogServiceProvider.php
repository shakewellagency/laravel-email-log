<?php

namespace Shakewell\EmailLog;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Shakewell\EmailLog\Http\SesEventController;
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

        $this->bootSesEvents();
    }

    private function bootSesEvents(): void
    {
        $configurationSet = config('email-log.ses_events.configuration_set');
        if ($configurationSet && ! config('services.ses.options.ConfigurationSetName')) {
            config(['services.ses.options.ConfigurationSetName' => $configurationSet]);
        }

        // No middleware group: SNS sends no session or CSRF token. The controller checks the topic and signature.
        if (config('email-log.ses_events.topic_arn') && ! $this->app->routesAreCached()) {
            Route::post(config('email-log.ses_events.path'), SesEventController::class)
                ->middleware('throttle:300,1')
                ->name('email-log.ses-events');
        }
    }
}
