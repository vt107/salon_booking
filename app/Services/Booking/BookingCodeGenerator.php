<?php

namespace App\Services\Booking;

use App\Models\Booking;

class BookingCodeGenerator
{
    // Bỏ các ký tự dễ nhầm khi khách đọc qua điện thoại: 0/O, 1/I/L
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LENGTH = 6;

    public function generate(): string
    {
        do {
            $code = 'BK'.$this->random();
        } while (Booking::where('code', $code)->exists());

        return $code;
    }

    private function random(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
