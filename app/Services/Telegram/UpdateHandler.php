<?php

namespace App\Services\Telegram;

use App\Enums\BookingStatus;
use App\Enums\TelegramMessageType;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;

/**
 * Xử lý update từ Telegram (webhook hoặc polling): lệnh chat và nút bấm Xác nhận / Từ chối.
 */
class UpdateHandler
{
    public function __construct(
        private TelegramClient $telegram,
        private BookingService $bookings,
        private BookingMessage $messages,
        private AdminNotifier $notifier,
    ) {}

    /** @param  array<string, mixed>  $update */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['message']['text'])) {
            $this->handleMessage($update['message']);
        }
    }

    /** @param  array<string, mixed>  $message */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        [$command, $argument] = array_pad(preg_split('/\s+/', trim($message['text']), 2), 2, null);
        $command = strtolower(explode('@', $command)[0]);

        match ($command) {
            '/start' => $argument ? $this->linkAccount($chatId, $message['from'], $argument) : $this->telegram->sendMessage($chatId, $this->help()),
            '/chatid' => $this->telegram->sendMessage($chatId, 'Chat ID: <code>'.$chatId.'</code>'."\nDán số này vào Admin → Cài đặt → Telegram để cả group nhận thông báo."),
            '/choduyet' => $this->whenAuthorized($chatId, $message['from'], fn () => $this->sendPending($chatId)),
            '/homnay' => $this->whenAuthorized($chatId, $message['from'], fn () => $this->telegram->sendMessage($chatId, app(DailyReports::class)->summary(today()))),
            '/help' => $this->telegram->sendMessage($chatId, $this->help()),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $query */
    private function handleCallback(array $query): void
    {
        [$action, $bookingId, $argument] = array_pad(explode(':', (string) ($query['data'] ?? '')), 3, null);
        $chatId = $query['message']['chat']['id'] ?? null;
        $messageId = $query['message']['message_id'] ?? null;

        $user = $this->approver($query['from']['id']);
        if (! $user) {
            $this->telegram->answerCallbackQuery($query['id'], 'Tài khoản Telegram này chưa liên kết với admin hoặc không có quyền duyệt lịch.', alert: true);

            return;
        }

        $booking = Booking::find((int) $bookingId);
        if (! $booking) {
            $this->telegram->answerCallbackQuery($query['id'], 'Không tìm thấy lịch hẹn.', alert: true);

            return;
        }

        try {
            match (true) {
                $action === 'confirm' => $this->bookings->confirm($booking, $user),
                $action === 'reject' && $argument === null => $this->telegram->editMessageReplyMarkup($chatId, $messageId, $this->messages->rejectReasonsKeyboard($booking)),
                $action === 'reject' => $this->bookings->reject($booking, $user, BookingMessage::REJECT_REASONS[(int) $argument] ?? null),
                $action === 'back' => $this->telegram->editMessageReplyMarkup($chatId, $messageId, $this->messages->keyboard($booking)),
                default => null,
            };
        } catch (BookingException $e) {
            // Thường gặp: admin khác vừa xử lý lịch này
            $this->notifier->refresh($booking);
            $this->telegram->answerCallbackQuery($query['id'], $e->getMessage(), alert: true);

            return;
        }

        $feedback = match (true) {
            $action === 'confirm' => '✅ Đã xác nhận '.$booking->code,
            $action === 'reject' && $argument !== null => '❌ Đã từ chối '.$booking->code,
            default => null,
        };

        if ($feedback) {
            // Cập nhật ngay để người bấm thấy kết quả, không chờ queue
            $this->notifier->refresh($booking);
        }

        $this->telegram->answerCallbackQuery($query['id'], $feedback);
    }

    /** @param  array<string, mixed>  $from */
    private function linkAccount(int $chatId, array $from, string $token): void
    {
        $user = User::where('telegram_link_token', $token)
            ->where('telegram_link_expires_at', '>', now())
            ->first();

        if (! $user) {
            $this->telegram->sendMessage($chatId, 'Link kết nối không hợp lệ hoặc đã hết hạn. Vào Admin → Kết nối Telegram để tạo link mới.');

            return;
        }

        // Một tài khoản Telegram chỉ gắn với một user
        User::where('telegram_user_id', $from['id'])->whereKeyNot($user->id)->update(['telegram_user_id' => null, 'telegram_username' => null]);

        $user->forceFill([
            'telegram_user_id' => $from['id'],
            'telegram_username' => $from['username'] ?? null,
            'telegram_link_token' => null,
            'telegram_link_expires_at' => null,
        ])->save();

        $this->telegram->sendMessage($chatId, '✅ Đã liên kết với tài khoản <b>'.e($user->name).'</b>. Bạn sẽ nhận thông báo lịch mới tại đây.'."\n\n".$this->help());
    }

    /** @param  array<string, mixed>  $from */
    private function whenAuthorized(int $chatId, array $from, callable $callback): void
    {
        $this->approver($from['id'])
            ? $callback()
            : $this->telegram->sendMessage($chatId, 'Bạn cần liên kết tài khoản admin trước (Admin → Kết nối Telegram).');
    }

    private function sendPending(int $chatId): void
    {
        $pending = Booking::where('status', BookingStatus::Pending)->orderBy('approval_deadline_at')->take(10)->get();

        if ($pending->isEmpty()) {
            $this->telegram->sendMessage($chatId, '👌 Không có lịch nào chờ duyệt.');

            return;
        }

        foreach ($pending as $booking) {
            $sent = $this->telegram->sendMessage($chatId, $this->messages->text($booking, '⏳ <b>Chờ duyệt</b>'), $this->messages->keyboard($booking));
            $booking->telegramMessages()->create(['chat_id' => $chatId, 'message_id' => $sent['message_id'], 'type' => TelegramMessageType::NewBooking]);
        }
    }

    private function approver(int $telegramUserId): ?User
    {
        $user = User::firstWhere('telegram_user_id', $telegramUserId);

        return $user?->canApproveBookings() ? $user : null;
    }

    private function help(): string
    {
        return implode("\n", [
            '<b>Các lệnh</b>',
            '/choduyet: lịch đang chờ duyệt',
            '/homnay: lịch hôm nay theo thợ',
            '/chatid: xem Chat ID (dùng khi thêm bot vào group)',
        ]);
    }
}
