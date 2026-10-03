<?php

namespace App\Services\Telegram;

use App\Support\Demo\DemoMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Client tối giản cho Telegram Bot API (https://core.telegram.org/bots/api).
 * Tin nhắn dùng parse_mode HTML: nội dung động phải được escape bằng e().
 */
class TelegramClient
{
    public function __construct(private ?string $token) {}

    public function isConfigured(): bool
    {
        return filled($this->token);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return mixed trường "result" của Telegram
     *
     * @throws TelegramException
     */
    public function call(string $method, array $params = [], int $timeout = 10): mixed
    {
        if (! $this->isConfigured()) {
            throw new TelegramException('Chưa cấu hình TELEGRAM_ADMIN_BOT_TOKEN.');
        }

        // Bản demo: token là giá trị giả, không gọi Telegram (kể cả từ scheduler / queue)
        if (DemoMode::enabled()) {
            throw new TelegramException("{$method}: bản demo không kết nối Telegram.");
        }

        try {
            $response = Http::baseUrl("https://api.telegram.org/bot{$this->token}/")
                ->timeout($timeout)
                ->acceptJson()
                ->post($method, array_filter($params, fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new TelegramException("{$method}: không kết nối được Telegram ({$e->getMessage()})", previous: $e);
        }

        if (! $response->json('ok')) {
            throw new TelegramException("{$method}: ".($response->json('description') ?? $response->status()));
        }

        return $response->json('result');
    }

    /**
     * @param  list<list<array<string, string>>>|null  $keyboard  inline keyboard
     * @return array<string, mixed> message
     */
    public function sendMessage(int|string $chatId, string $html, ?array $keyboard = null): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $keyboard !== null ? ['inline_keyboard' => $keyboard] : null,
        ]);
    }

    /** @param  list<list<array<string, string>>>  $keyboard  [] = bỏ hết nút */
    public function editMessageText(int|string $chatId, int $messageId, string $html, array $keyboard = []): void
    {
        $this->ignoreNotModified(fn () => $this->call('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]));
    }

    /** @param  list<list<array<string, string>>>  $keyboard */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, array $keyboard): void
    {
        $this->ignoreNotModified(fn () => $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $alert ?: null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function getUpdates(?int $offset, int $timeout = 25): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeout + 5);
    }

    public function setWebhook(string $url, ?string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook');
    }

    /** @return array<string, mixed> */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /** @return array<string, mixed> */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /** Sửa tin nhắn bằng đúng nội dung cũ: Telegram báo lỗi "message is not modified", bỏ qua */
    private function ignoreNotModified(callable $callback): void
    {
        try {
            $callback();
        } catch (TelegramException $e) {
            if (! str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }
        }
    }
}
