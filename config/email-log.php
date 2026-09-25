<?php

return [
    // Record every email the app sends in the sent_emails table.
    'enabled' => env('EMAIL_LOG_ENABLED', true),

    // Delete records older than this many days when `php artisan model:prune` runs. Null keeps them forever.
    'retention_days' => env('EMAIL_LOG_RETENTION_DAYS'),
];
