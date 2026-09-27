<?php

namespace App\Notifications\Customer;

class BookingReminderMail extends BookingMail
{
    protected function subject(): string
    {
        return 'Nhắc lịch hẹn '.($this->booking->start_at->isToday() ? 'hôm nay' : 'ngày mai');
    }

    protected function heading(): string
    {
        return 'Sắp đến giờ hẹn của bạn';
    }

    protected function paragraphs(): array
    {
        return [
            "Tiệm nhắc bạn lịch hẹn lúc **{$this->when()}** với **{$this->booking->staff->name}**.",
            'Nếu không đến được, bạn vui lòng hủy lịch hoặc báo cho tiệm để tiệm sắp xếp cho khách khác.',
        ];
    }
}
