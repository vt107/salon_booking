<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Ewallet = 'ewallet';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Tiền mặt',
            self::BankTransfer => 'Chuyển khoản',
            self::Card => 'Thẻ',
            self::Ewallet => 'Ví điện tử',
        };
    }
}
