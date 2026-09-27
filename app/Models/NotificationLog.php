<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['booking_id', 'notifiable_type', 'notifiable_id', 'type', 'channel', 'recipient', 'status', 'error', 'sent_at'])]
class NotificationLog extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
