<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use RuntimeException;

/**
 * Lỗi nghiệp vụ khi đặt / đổi trạng thái booking. Message hiển thị được cho người dùng.
 */
class BookingException extends RuntimeException
{
    public static function invalidPhone(): self
    {
        return new self('Số điện thoại không hợp lệ.');
    }

    public static function noServices(): self
    {
        return new self('Vui lòng chọn ít nhất một dịch vụ.');
    }

    public static function tooSoon(int $minutes): self
    {
        return new self("Vui lòng đặt lịch trước ít nhất {$minutes} phút.");
    }

    public static function tooFarAhead(int $days): self
    {
        return new self("Chỉ nhận đặt lịch trong vòng {$days} ngày tới.");
    }

    public static function customerBlocked(): self
    {
        return new self('Không thể đặt lịch online, vui lòng liên hệ trực tiếp với tiệm.');
    }

    public static function tooManyPending(int $max): self
    {
        return new self("Bạn đang có {$max} lịch chờ xác nhận. Vui lòng đợi tiệm xác nhận trước khi đặt thêm.");
    }

    public static function staffCannotPerform(): self
    {
        return new self('Nhân viên đã chọn không làm được tất cả dịch vụ này.');
    }

    public static function slotUnavailable(): self
    {
        return new self('Khung giờ này vừa có người đặt, vui lòng chọn giờ khác.');
    }

    public static function invalidTransition(BookingStatus $from, BookingStatus $to): self
    {
        return new self("Không thể chuyển lịch từ \"{$from->getLabel()}\" sang \"{$to->getLabel()}\".");
    }

    public static function cannotReschedule(BookingStatus $status): self
    {
        return new self("Không thể đổi lịch đang ở trạng thái \"{$status->getLabel()}\".");
    }

    public static function cancellationWindowClosed(int $hours): self
    {
        return new self("Chỉ có thể hủy lịch trước giờ hẹn ít nhất {$hours} giờ. Vui lòng liên hệ tiệm.");
    }
}
