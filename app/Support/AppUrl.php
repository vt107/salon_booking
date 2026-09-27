<?php

namespace App\Support;

class AppUrl
{
    /**
     * APP_URL có phải tên miền công khai không. Link gửi ra ngoài (nút Telegram, mã QR in ra)
     * trỏ tới localhost / IP / *.test thì người nhận không mở được.
     */
    public static function isPublic(): bool
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?? '';

        return str_contains($host, '.')
            && ! filter_var($host, FILTER_VALIDATE_IP)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local');
    }
}
