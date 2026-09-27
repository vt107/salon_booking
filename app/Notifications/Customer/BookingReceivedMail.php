<?php

namespace App\Notifications\Customer;

class BookingReceivedMail extends BookingMail
{
    protected function subject(): string
    {
        return 'Đã nhận yêu cầu đặt lịch';
    }

    protected function heading(): string
    {
        return 'Tiệm đã nhận yêu cầu của bạn';
    }

    protected function paragraphs(): array
    {
        return [
            "Lịch hẹn lúc **{$this->when()}** đang được giữ chỗ và chờ tiệm xác nhận. Bạn sẽ nhận thêm một email ngay khi tiệm duyệt.",
            'Nếu thay đổi kế hoạch, bạn có thể tự hủy lịch bằng nút bên dưới.',
        ];
    }
}
