<?php

namespace App\Services\Voucher;

use RuntimeException;

class VoucherException extends RuntimeException
{
    public static function notFound(): self
    {
        return new self('Mã giảm giá không tồn tại hoặc đã ngừng áp dụng.');
    }

    public static function notStarted(): self
    {
        return new self('Mã giảm giá chưa đến thời gian áp dụng.');
    }

    public static function expired(): self
    {
        return new self('Mã giảm giá đã hết hạn.');
    }

    public static function exhausted(): self
    {
        return new self('Mã giảm giá đã hết lượt sử dụng.');
    }

    public static function customerLimitReached(): self
    {
        return new self('Bạn đã dùng hết số lần cho phép của mã giảm giá này.');
    }

    public static function firstBookingOnly(): self
    {
        return new self('Mã giảm giá chỉ áp dụng cho lần đặt lịch đầu tiên.');
    }

    public static function minOrderNotMet(int $minAmount): self
    {
        return new self('Mã giảm giá áp dụng cho đơn từ '.number_format($minAmount, 0, ',', '.').'đ.');
    }

    public static function notApplicable(): self
    {
        return new self('Mã giảm giá không áp dụng cho các dịch vụ đã chọn.');
    }
}
