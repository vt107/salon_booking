<?php

namespace App\Services\Telegram;

use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\Money;

/**
 * Nội dung và bàn phím inline của tin nhắn Telegram về một lịch hẹn.
 */
class BookingMessage
{
    /** Lý do từ chối nhanh (chỉ số nằm trong callback_data "reject:{id}:{i}") */
    public const REJECT_REASONS = [
        'Hết chỗ vào khung giờ này',
        'Thợ bận đột xuất',
        'Tiệm nghỉ vào ngày này',
    ];

    private const WEEKDAYS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

    public function text(Booking $booking, string $title = '🆕 <b>Lịch mới chờ duyệt</b>'): string
    {
        $booking->loadMissing(['customer', 'staff', 'items', 'voucher']);
        $customer = $booking->customer;
        $history = $customer->total_visits ? "khách quen · {$customer->total_visits} lần" : 'khách mới';
        if ($customer->no_show_count) {
            $history .= " · ⚠️ {$customer->no_show_count} lần không đến";
        }

        $lines = [
            $title.' · <code>'.e($booking->code).'</code>',
            '',
            '👤 '.e($customer->name).' · '.e($customer->phone).' ('.$history.')',
            '✂️ '.e($booking->items->pluck('service_name')->implode(', ')),
            '🕒 '.$booking->start_at->format('H:i').'–'.$booking->end_at->format('H:i').', '
                .self::WEEKDAYS[$booking->start_at->dayOfWeek].' '.$booking->start_at->format('d/m'),
            '💇 '.e($booking->staff->name).($booking->is_staff_auto_assigned ? ' (tự gán)' : ''),
            '💰 '.Money::format($booking->total)
                .($booking->discount_amount ? ' (giảm '.Money::format($booking->discount_amount).' '.e($booking->voucher?->code).')' : ''),
        ];

        if ($booking->customer_note) {
            $lines[] = '📝 '.e($booking->customer_note);
        }

        $lines[] = '';
        $lines[] = $this->statusLine($booking);

        return implode("\n", $lines);
    }

    public function statusLine(Booking $booking): string
    {
        $actor = $booking->statusHistories()->first()?->actor?->name;
        $at = $booking->updated_at->format('H:i d/m');

        return match ($booking->status) {
            BookingStatus::Pending => '⏳ Hạn duyệt: <b>'.$booking->approval_deadline_at?->format('H:i d/m').'</b>',
            BookingStatus::Confirmed => '✅ <b>Đã xác nhận</b>'.($booking->confirmedBy ? ' bởi '.e($booking->confirmedBy->name) : '')." lúc {$at}",
            BookingStatus::Rejected => '❌ <b>Đã từ chối</b>'.($actor ? ' bởi '.e($actor) : '').($booking->status_reason ? ': '.e($booking->status_reason) : ''),
            BookingStatus::Cancelled => match ($booking->cancelled_by) {
                CancelledBy::System => '⌛ <b>Tự hủy</b> vì quá hạn duyệt',
                CancelledBy::Customer => '🚫 <b>Khách đã hủy</b>'.($booking->status_reason ? ': '.e($booking->status_reason) : ''),
                default => '🚫 <b>Tiệm đã hủy</b>'.($booking->status_reason ? ': '.e($booking->status_reason) : ''),
            },
            default => '• '.$booking->status->getLabel(),
        };
    }

    /** @return list<list<array<string, string>>> */
    public function keyboard(Booking $booking): array
    {
        $rows = [];

        if ($booking->status === BookingStatus::Pending) {
            $rows[] = [
                ['text' => '✅ Xác nhận', 'callback_data' => "confirm:{$booking->id}"],
                ['text' => '❌ Từ chối', 'callback_data' => "reject:{$booking->id}"],
            ];
        }

        if ($url = $this->adminUrl($booking)) {
            $rows[] = [['text' => '🔗 Xem trong admin', 'url' => $url]];
        }

        return $rows;
    }

    /** @return list<list<array<string, string>>> */
    public function rejectReasonsKeyboard(Booking $booking): array
    {
        $rows = array_map(
            fn (string $reason, int $i) => [['text' => $reason, 'callback_data' => "reject:{$booking->id}:{$i}"]],
            self::REJECT_REASONS,
            array_keys(self::REJECT_REASONS),
        );
        $rows[] = [['text' => '↩️ Quay lại', 'callback_data' => "back:{$booking->id}"]];

        return $rows;
    }

    /** Telegram từ chối nút URL trỏ tới localhost / IP nội bộ, nên chỉ thêm khi APP_URL là tên miền thật */
    private function adminUrl(Booking $booking): ?string
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?? '';

        if (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return null;
        }

        return BookingResource::getUrl('view', ['record' => $booking], panel: 'admin');
    }
}
