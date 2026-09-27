<?php

namespace App\Services\Booking;

use App\Models\Setting;

/**
 * Truy cập có kiểu cho nhóm cấu hình "booking" trong bảng settings.
 */
class BookingSettings
{
    public const DEFAULTS = [
        'slot_interval_minutes' => 15,
        // Khách phải đặt trước ít nhất bao nhiêu phút
        'min_lead_minutes' => 60,
        'max_advance_days' => 30,
        // Hạn duyệt = min(thời điểm tiệm mở cửa gần nhất + approval_minutes, start_at - approval_min_before_start)
        'approval_minutes' => 60,
        'approval_min_before_start' => 30,
        'cancel_deadline_hours' => 2,
        'max_pending_per_phone' => 2,
        'reminder_hours_before' => 24,
        'no_show_grace_minutes' => 30,
    ];

    public function slotInterval(): int
    {
        return $this->int('slot_interval_minutes');
    }

    public function minLeadMinutes(): int
    {
        return $this->int('min_lead_minutes');
    }

    public function maxAdvanceDays(): int
    {
        return $this->int('max_advance_days');
    }

    public function approvalMinutes(): int
    {
        return $this->int('approval_minutes');
    }

    public function approvalMinBeforeStart(): int
    {
        return $this->int('approval_min_before_start');
    }

    public function cancelDeadlineHours(): int
    {
        return $this->int('cancel_deadline_hours');
    }

    public function maxPendingPerPhone(): int
    {
        return $this->int('max_pending_per_phone');
    }

    public function reminderHoursBefore(): int
    {
        return $this->int('reminder_hours_before');
    }

    public function noShowGraceMinutes(): int
    {
        return $this->int('no_show_grace_minutes');
    }

    private function int(string $key): int
    {
        return (int) Setting::get("booking.{$key}", self::DEFAULTS[$key]);
    }
}
