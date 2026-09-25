<?php

namespace Shakewell\EmailLog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class SentEmail extends Model
{
    use MassPrunable;

    protected $table = 'sent_emails';

    protected $guarded = ['id'];

    public function prunable(): Builder
    {
        $days = config('email-log.retention_days');

        return $days
            ? static::where('created_at', '<', now()->subDays((int) $days))
            : static::whereRaw('1 = 0');
    }
}
