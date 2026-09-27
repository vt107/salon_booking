<?php

namespace App\Services\Telegram;

/**
 * Các thao tác cài đặt bot, dùng chung cho trang Cài đặt và lệnh telegram:set-webhook.
 */
class TelegramSetup
{
    public function __construct(private TelegramConfig $config) {}

    /**
     * Kiểm tra token với Telegram (getMe).
     *
     * @return array{id: int, username: string, first_name: string}
     *
     * @throws TelegramException token sai / không kết nối được
     */
    public function verify(string $token): array
    {
        return (new TelegramClient($token))->getMe();
    }

    /**
     * Webhook cần HTTPS công khai; máy dev dùng polling (make telegram).
     * Dựng từ APP_URL thay vì request hiện tại: sau proxy / chạy từ CLI vẫn ra đúng https://.
     */
    public function webhookUrl(): ?string
    {
        $url = rtrim((string) config('app.url'), '/').route('telegram.webhook', absolute: false);

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /** @throws TelegramException */
    public function enableWebhook(): string
    {
        $url = $this->webhookUrl() ?? throw new TelegramException('Webhook cần APP_URL dạng https:// công khai. Trên máy dev hãy chạy: make telegram');

        $this->client()->setWebhook($url, $this->config->ensureWebhookSecret());

        return $url;
    }

    /** @throws TelegramException */
    public function disableWebhook(): void
    {
        $this->client()->deleteWebhook();
    }

    /**
     * @return array{bot: string, webhook: ?string, pending: int, last_error: ?string}
     *
     * @throws TelegramException
     */
    public function status(): array
    {
        $client = $this->client();
        $bot = $client->getMe();
        $info = $client->getWebhookInfo();

        return [
            'bot' => $bot['username'],
            'webhook' => $info['url'] ?: null,
            'pending' => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => $info['last_error_message'] ?? null,
        ];
    }

    private function client(): TelegramClient
    {
        return new TelegramClient($this->config->token());
    }
}
