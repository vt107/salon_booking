<?php

namespace App\Models;

use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'description', 'type', 'value', 'max_discount', 'min_order_amount',
    'starts_at', 'ends_at', 'usage_limit', 'usage_limit_per_customer', 'scope', 'first_booking_only', 'is_active',
])]
class Voucher extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'scope' => VoucherScope::class,
            'value' => 'integer',
            'max_discount' => 'integer',
            'min_order_amount' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'used_count' => 'integer',
            'first_booking_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Mã voucher luôn lưu chữ in hoa để so khớp không phân biệt hoa thường */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtoupper(trim($value)));
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'voucher_service');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }
}
