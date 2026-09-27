<?php

namespace App\Notifications\Customer;

use App\Services\Booking\BookingSettings;

class BookingConfirmedMail extends BookingMail
{
    protected function subject(): string
    {
        return 'Lịch hẹn đã được xác nhận';
    }

    protected function heading(): string
    {
        return 'Hẹn gặp bạn!';
    }

    protected function paragraphs(): array
    {
        $hours = app(BookingSettings::class)->cancelDeadlineHours();

        return [
            "Tiệm đã xác nhận lịch hẹn của bạn lúc **{$this->when()}** với **{$this->booking->staff->name}**.",
            "Nếu không đến được, bạn vui lòng hủy online trước giờ hẹn ít nhất {$hours} giờ để tiệm nhường chỗ cho khách khác.",
        ];
    }
}
