<?php

namespace App\Services\Telegram;

use App\Enums\TelegramMessageType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Gửi thông báo lịch hẹn cho chủ tiệm / quản lý qua Telegram.
 *
 * Người nhận: group của tiệm nếu đã cài "telegram.group_chat_id", nếu không thì chat riêng
 * của từng admin / quản lý đã liên kết Telegram và bật nhận thông báo.
 */
class AdminNotifier
{
    public function __construct(
        private TelegramClient $telegram,
        private BookingMessage $messages,
    ) {}

    public function isEnabled(): bool
    {
        return $this->telegram->isConfigured() && $this->chatIds() !== [];
    }

    /** @return list<int> */
    public function chatIds(): array
    {
        if ($group = Setting::get('telegram.group_chat_id')) {
            return [(int) $group];
        }

        return User::where('is_active', true)
            ->where('notify_telegram', true)
            ->whereNotNull('telegram_user_id')
            ->whereIn('role', [UserRole::Admin, UserRole::Manager])
            ->pluck('telegram_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function newBooking(Booking $booking): void
    {
        $this->sendAndTrack($booking, $this->messages->text($booking), TelegramMessageType::NewBooking);
    }

    public function approvalReminder(Booking $booking): void
    {
        $this->sendAndTrack($booking, $this->messages->text($booking, '⏰ <b>Sắp hết hạn duyệt</b>'), TelegramMessageType::ApprovalReminder);
    }

    public function customerCancelled(Booking $booking): void
    {
        $this->broadcast($this->messages->text($booking, '🚫 <b>Khách hủy lịch</b>'));
    }

    /** Cập nhật mọi tin nhắn đã gửi về booking: dòng trạng thái mới, bỏ nút khi đã xử lý xong */
    public function refresh(Booking $booking): void
    {
        $booking->refresh();

        foreach ($booking->telegramMessages as $message) {
            $title = $message->type === TelegramMessageType::ApprovalReminder ? '⏰ <b>Sắp hết hạn duyệt</b>' : '🆕 <b>Lịch mới</b>';

            try {
                $this->telegram->editMessageText($message->chat_id, $message->message_id, $this->messages->text($booking, $title), $this->messages->keyboard($booking));
            } catch (TelegramException $e) {
                Log::warning('Không sửa được tin nhắn Telegram', ['booking' => $booking->code, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @param  list<list<array<string, string>>>|null  $keyboard */
    public function broadcast(string $html, ?array $keyboard = null): void
    {
        foreach ($this->chatIds() as $chatId) {
            try {
                $this->telegram->sendMessage($chatId, $html, $keyboard);
            } catch (TelegramException $e) {
                Log::warning('Không gửi được tin nhắn Telegram', ['chat' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function sendAndTrack(Booking $booking, string $html, TelegramMessageType $type): void
    {
        // Lỗi ở một chat không chặn các chat khác, và không làm job retry gửi trùng
        foreach ($this->chatIds() as $chatId) {
            try {
                $sent = $this->telegram->sendMessage($chatId, $html, $this->messages->keyboard($booking));
            } catch (TelegramException $e) {
                Log::warning('Không gửi được tin nhắn Telegram', ['chat' => $chatId, 'booking' => $booking->code, 'error' => $e->getMessage()]);

                continue;
            }

            TelegramMessage::create([
                'booking_id' => $booking->id,
                'chat_id' => $chatId,
                'message_id' => $sent['message_id'],
                'type' => $type,
            ]);
        }
    }
}
