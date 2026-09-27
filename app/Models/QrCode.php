<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'staff_id', 'service_id', 'voucher_id', 'is_active'])]
class QrCode extends Model
{
    protected function casts(): array
    {
        return [
            'scan_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // Bỏ ký tự dễ nhầm khi gõ tay: 0/O, 1/I/L
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function generateCode(int $length = 6): string
    {
        do {
            $code = collect(range(1, $length))->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])->implode('');
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** Link in trên mã QR */
    public function url(): string
    {
        return route('qr.redirect', ['code' => $this->code]);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
