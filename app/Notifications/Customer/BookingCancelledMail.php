<?php

namespace App\Notifications\Customer;

use App\Enums\CancelledBy;

class BookingCancelledMail extends BookingMail
{
    protected function subject(): string
    {
        return 'Lịch hẹn đã được hủy';
    }

    protected function heading(): string
    {
        return match ($this->booking->cancelled_by) {
            CancelledBy::System => 'Tiệm chưa kịp xác nhận lịch của bạn',
            CancelledBy::Staff => 'Tiệm đã hủy lịch hẹn của bạn',
            default => 'Bạn đã hủy lịch hẹn',
        };
    }

    protected function paragraphs(): array
    {
        return match ($this->booking->cancelled_by) {
            CancelledBy::System => [
                "Lịch hẹn lúc **{$this->when()}** chưa được tiệm xác nhận kịp nên đã tự hủy để không giữ chỗ quá lâu.",
                'Thành thật xin lỗi bạn. Bạn có thể đặt lại, hoặc gọi trực tiếp cho tiệm để được xếp lịch nhanh nhất.',
            ],
            CancelledBy::Staff => array_values(array_filter([
                "Tiệm rất tiếc phải hủy lịch hẹn lúc **{$this->when()}**.",
                $this->booking->status_reason ? "Lý do: {$this->booking->status_reason}." : null,
                'Mong bạn thông cảm và đặt một khung giờ khác.',
            ])),
            default => [
                "Lịch hẹn lúc **{$this->when()}** đã được hủy theo yêu cầu của bạn.",
                'Hẹn gặp bạn vào dịp khác!',
            ],
        };
    }
}
