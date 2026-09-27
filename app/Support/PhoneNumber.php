<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Chuẩn hóa SĐT Việt Nam về dạng 0xxxxxxxxx: "+84 90-123 4567", "84901234567" => "0901234567".
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    public static function isValid(string $phone): bool
    {
        return (bool) preg_match('/^0[35789]\d{8}$/', self::normalize($phone));
    }
}
