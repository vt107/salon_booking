<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:set-webhook {--delete : Gỡ webhook} {--info : Chỉ xem trạng thái}')]
#[Description('Đăng ký webhook Telegram trỏ về /telegram/webhook (cần APP_URL là https công khai)')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        $bot = $telegram->getMe();
        $this->info("Bot: @{$bot['username']}");

        if ($this->option('info')) {
            $info = $telegram->getWebhookInfo();
            $this->line('Webhook: '.($info['url'] ?: '(chưa đặt)'));
            $this->line('Update chờ xử lý: '.($info['pending_update_count'] ?? 0));
            if (! empty($info['last_error_message'])) {
                $this->warn('Lỗi gần nhất: '.$info['last_error_message']);
            }

            return self::SUCCESS;
        }

        if ($this->option('delete')) {
            $telegram->deleteWebhook();
            $this->info('Đã gỡ webhook.');

            return self::SUCCESS;
        }

        $url = route('telegram.webhook');
        if (! str_starts_with($url, 'https://')) {
            $this->error("Webhook cần HTTPS công khai, hiện là {$url}. Trên máy dev hãy dùng: php artisan telegram:poll");

            return self::FAILURE;
        }
        if (blank(config('services.telegram.webhook_secret'))) {
            $this->error('Thiếu TELEGRAM_ADMIN_WEBHOOK_SECRET trong .env');

            return self::FAILURE;
        }

        $telegram->setWebhook($url, config('services.telegram.webhook_secret'));
        $this->info("Đã đặt webhook: {$url}");

        return self::SUCCESS;
    }
}
