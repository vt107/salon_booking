<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Cấu hình bot Telegram: ưu tiên giá trị admin nhập ở Cài đặt → Telegram (lưu mã hóa bằng APP_KEY),
 * không có thì dùng .env (TELEGRAM_ADMIN_*).
 */
class TelegramConfig
{
    public function token(): ?string
    {
        return $this->encrypted('bot_token') ?? (config('services.telegram.bot_token') ?: null);
    }

    public function username(): ?string
    {
        return Setting::get('telegram.bot_username') ?: (config('services.telegram.bot_username') ?: null);
    }

    public function webhookSecret(): ?string
    {
        return $this->encrypted('webhook_secret') ?? (config('services.telegram.webhook_secret') ?: null);
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    /** Token đang được lấy từ đâu: "admin", "env" hoặc null */
    public function source(): ?string
    {
        return match (true) {
            $this->encrypted('bot_token') !== null => 'admin',
            filled(config('services.telegram.bot_token')) => 'env',
            default => null,
        };
    }

    /** "7412345678:AAH...xYz9" => "7412…xYz9": đủ nhận ra token nào, không lộ token */
    public function tokenHint(): ?string
    {
        $token = $this->token();

        return $token ? Str::before($token, ':').'…'.substr($token, -4) : null;
    }

    public function saveBot(string $token, string $username): void
    {
        Setting::set('telegram.bot_token', Crypt::encryptString($token));
        Setting::set('telegram.bot_username', $username);
    }

    /** Secret gửi kèm mỗi request webhook; tạo mới nếu chưa có */
    public function ensureWebhookSecret(): string
    {
        if ($secret = $this->webhookSecret()) {
            return $secret;
        }

        $secret = Str::random(48);
        Setting::set('telegram.webhook_secret', Crypt::encryptString($secret));

        return $secret;
    }

    public function forget(): void
    {
        foreach (['bot_token', 'bot_username', 'webhook_secret'] as $key) {
            Setting::set("telegram.{$key}", null);
        }
    }

    private function encrypted(string $key): ?string
    {
        $value = Setting::get("telegram.{$key}");

        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // APP_KEY đã đổi: coi như chưa cấu hình, admin nhập lại
            return null;
        }
    }
}
