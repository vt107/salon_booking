<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
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
    public function handle(TelegramClient $telegram, UpdateHandler $handler): int
    {
        // Bot đang có webhook thì getUpdates bị Telegram từ chối
        $telegram->deleteWebhook();
        $this->info('Đang lắng nghe Telegram... (Ctrl+C để dừng)');

        $offset = null;

        do {
            try {
                $updates = $telegram->getUpdates($offset, $this->option('once') ? 0 : 25);
            } catch (TelegramException $e) {
                $this->error($e->getMessage());
                sleep(5);

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
