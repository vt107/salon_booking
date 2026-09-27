<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CancelledBy: string implements HasLabel
{
    case Customer = 'customer';
    case Staff = 'staff';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => 'Khách hàng',
            self::Staff => 'Nhân viên',
            self::System => 'Hệ thống',
        };
    }
}
