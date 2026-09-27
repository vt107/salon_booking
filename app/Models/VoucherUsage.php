<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['voucher_id', 'booking_id', 'customer_id', 'discount_amount', 'released_at'])]
class VoucherUsage extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
