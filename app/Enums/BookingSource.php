<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BookingSource: string implements HasLabel
{
    case Web = 'web';
    case Telegram = 'telegram';
    case Qr = 'qr';
    case Admin = 'admin';
    case Phone = 'phone';
    case WalkIn = 'walk_in';

    public function getLabel(): string
    {
        return match ($this) {
            self::Web => 'Website',
            self::Telegram => 'Telegram',
            self::Qr => 'Mã QR',
            self::Admin => 'Admin tạo',
            self::Phone => 'Gọi điện',
            self::WalkIn => 'Khách vãng lai',
        };
    }
}
