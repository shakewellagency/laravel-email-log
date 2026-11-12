<?php

namespace Shakewell\EmailLog\Tests;

use Orchestra\Testbench\TestCase;
use Shakewell\EmailLog\EmailLogServiceProvider;

class SesEventsDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [EmailLogServiceProvider::class];
    }

    public function test_no_route_and_no_configuration_set_without_a_topic(): void
    {
        $this->assertFalse($this->app['router']->has('email-log.ses-events'));
        $this->assertNull(config('services.ses.options.ConfigurationSetName'));
        $this->post('/email-log/ses-events')->assertNotFound();
    }
}
