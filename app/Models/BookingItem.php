<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_id', 'service_id', 'service_name', 'price', 'discount_amount',
    'duration_minutes', 'start_at', 'end_at', 'sort_order',
])]
class BookingItem extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discount_amount' => 'integer',
            'duration_minutes' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }
}
