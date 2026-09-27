<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramConfig;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\UpdateHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:poll {--once : Chỉ lấy một lượt update rồi dừng}')]
#[Description('Nhận update Telegram bằng long polling (máy dev không có URL public cho webhook)')]
class TelegramPoll extends Command
{
    public function handle(TelegramConfig $config, UpdateHandler $handler): int
    {
        $this->info('Đang lắng nghe Telegram... (Ctrl+C để dừng)');

        $activeToken = null;
        $offset = null;

        do {
            // Đọc token mỗi vòng: admin lưu / đổi token trong Cài đặt là có hiệu lực, không cần khởi động lại
            $token = $config->token();

            if (! $token) {
                if ($this->option('once')) {
                    $this->warn('Chưa cấu hình bot Telegram.');

                    return self::FAILURE;
                }
                sleep(10);

                continue;
            }

            $telegram = new TelegramClient($token);

            try {
                if ($token !== $activeToken) {
                    // Bot đang có webhook thì getUpdates bị Telegram từ chối
                    $telegram->deleteWebhook();
                    $activeToken = $token;
                    $offset = null;
                    $this->info('Bot @'.$telegram->getMe()['username'].' đã sẵn sàng.');
                }

                $updates = $telegram->getUpdates($offset, $this->option('once') ? 0 : 25);
            } catch (TelegramException $e) {
                $this->error($e->getMessage());
                $activeToken = null;
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(10);

                continue;
            }

            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;

                try {
                    $handler->handle($update);
                    $this->line('✓ update '.$update['update_id']);
                } catch (Throwable $e) {
                    $this->error("update {$update['update_id']}: {$e->getMessage()}");
                }
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
