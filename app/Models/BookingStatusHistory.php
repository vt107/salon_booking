<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['booking_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note'])]
class BookingStatusHistory extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => BookingStatus::class,
            'to_status' => BookingStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** User, Customer hoặc null (hệ thống) */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
