<?php

namespace App\Notifications\Customer;

class BookingRejectedMail extends BookingMail
{
    protected function subject(): string
    {
        return 'Tiệm chưa nhận được lịch hẹn';
    }

    protected function heading(): string
    {
        return 'Rất tiếc, tiệm chưa nhận được lịch này';
    }

    protected function paragraphs(): array
    {
        return array_values(array_filter([
            "Tiệm không thể nhận lịch hẹn lúc **{$this->when()}**.",
            $this->booking->status_reason ? "Lý do: {$this->booking->status_reason}." : null,
            'Mong bạn thông cảm và chọn một khung giờ khác, tiệm rất mong được phục vụ bạn.',
        ]));
    }
}
