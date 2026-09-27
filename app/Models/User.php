<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'email', 'phone', 'password', 'role', 'is_active',
    'telegram_user_id', 'telegram_username', 'telegram_link_token', 'telegram_link_expires_at', 'notify_telegram',
])]
#[Hidden(['password', 'remember_token', 'telegram_link_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'telegram_link_expires_at' => 'datetime',
            'notify_telegram' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    public function canApproveBookings(): bool
    {
        return $this->is_active && $this->role->canApproveBookings();
    }

    public function hasTelegram(): bool
    {
        return $this->telegram_user_id !== null;
    }
}
