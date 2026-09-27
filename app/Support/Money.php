<?php

namespace App\Support;

class Money
{
    /** 150000 => "150.000đ" */
    public static function format(?int $amount): string
    {
        return number_format($amount ?? 0, 0, ',', '.').'đ';
    }
}
