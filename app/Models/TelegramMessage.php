<?php

namespace App\Models;

use App\Enums\TelegramMessageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'chat_id', 'message_id', 'type'])]
class TelegramMessage extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'message_id' => 'integer',
            'type' => TelegramMessageType::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
