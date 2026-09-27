<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\Gender;
use App\Enums\NotificationChannel;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'phone', 'email', 'password', 'gender', 'birthday', 'note', 'is_blocked',
    'telegram_user_id', 'telegram_username', 'preferred_channel',
])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'gender' => Gender::class,
            'birthday' => 'date',
            'is_blocked' => 'boolean',
            'preferred_channel' => NotificationChannel::class,
            'total_visits' => 'integer',
            'total_spent' => 'integer',
            'no_show_count' => 'integer',
            'last_visit_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (string $value) => PhoneNumber::normalize($value));
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function voucherUsages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function pendingBookings(): HasMany
    {
        return $this->bookings()->where('status', BookingStatus::Pending);
    }
}
