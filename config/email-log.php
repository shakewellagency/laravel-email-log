<?php

return [
    // Record every email the app sends in the sent_emails table.
    'enabled' => env('EMAIL_LOG_ENABLED', true),

    // Delete records older than this many days when `php artisan model:prune` runs. Null keeps them forever.
    'retention_days' => env('EMAIL_LOG_RETENTION_DAYS'),

    // SES delivery events (Send, Delivery, Bounce, Complaint, Reject, DeliveryDelay, Rendering Failure), published
    // by an SES configuration set to an SNS topic and stored in sent_email_events. Off until topic_arn is set.
    'ses_events' => [
        // The SNS topic this app's configuration set publishes to. The webhook route is only registered when set,
        // and it only accepts signed notifications from this exact topic.
        'topic_arn' => env('EMAIL_LOG_SES_TOPIC_ARN'),

        // The SES configuration set to send through. Applied to services.ses.options unless the app sets one there.
        'configuration_set' => env('EMAIL_LOG_SES_CONFIGURATION_SET'),

        // Where SNS posts the events. Subscribe https://<app>/<path> to the topic.
        'path' => env('EMAIL_LOG_SES_EVENTS_PATH', 'email-log/ses-events'),
    ],
];
