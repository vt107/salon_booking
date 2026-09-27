<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Staff = 'staff';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Quản trị',
            self::Manager => 'Quản lý',
            self::Staff => 'Nhân viên',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** Quản lý dịch vụ, nhân viên, voucher, QR */
    public function canManageCatalog(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /** Được duyệt / từ chối booking (trên admin và Telegram) */
    public function canApproveBookings(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }
}
