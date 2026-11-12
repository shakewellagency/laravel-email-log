<?php

namespace Shakewell\EmailLog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SES event (Send, Delivery, Bounce, Complaint, Reject, DeliveryDelay, Rendering Failure) for a sent email.
 */
class SentEmailEvent extends Model
{
    use MassPrunable;

    protected $table = 'sent_email_events';

    protected $guarded = ['id'];

    protected $casts = [
        'recipients' => 'array',
        'detail' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function sentEmail(): BelongsTo
    {
        return $this->belongsTo(SentEmail::class, 'message_id', 'message_id');
    }

    public function prunable(): Builder
    {
        $days = config('email-log.retention_days');

        return $days
            ? static::where('created_at', '<', now()->subDays((int) $days))
            : static::whereRaw('1 = 0');
    }
}
