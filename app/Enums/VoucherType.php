<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VoucherType: string implements HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percent => 'Giảm theo %',
            self::Fixed => 'Giảm số tiền',
        };
    }
}
