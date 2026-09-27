<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VoucherScope: string implements HasLabel
{
    case All = 'all';
    case Services = 'services';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'Tất cả dịch vụ',
            self::Services => 'Dịch vụ chỉ định',
        };
    }
}
