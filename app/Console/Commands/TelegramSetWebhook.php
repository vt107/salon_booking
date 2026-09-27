<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramConfig;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramSetup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:set-webhook {--delete : Gỡ webhook} {--info : Chỉ xem trạng thái}')]
#[Description('Đăng ký webhook Telegram (cũng làm được ở Admin → Cài đặt → Telegram)')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramConfig $config, TelegramSetup $setup): int
    {
        if (! $config->isConfigured()) {
            $this->error('Chưa có token bot: nhập ở Admin → Cài đặt → Telegram (hoặc TELEGRAM_ADMIN_BOT_TOKEN trong .env).');

            return self::FAILURE;
        }

        try {
            if ($this->option('delete')) {
                $setup->disableWebhook();
                $this->info('Đã gỡ webhook.');
            } elseif (! $this->option('info')) {
                $this->info('Đã đặt webhook: '.$setup->enableWebhook());
            }

            $status = $setup->status();
        } catch (TelegramException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Bot: @{$status['bot']}");
        $this->line('Webhook: '.($status['webhook'] ?? '(chưa đặt)'));
        $this->line("Update chờ xử lý: {$status['pending']}");
        if ($status['last_error']) {
            $this->warn("Lỗi gần nhất: {$status['last_error']}");
        }

        return self::SUCCESS;
    }
}
