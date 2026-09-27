<?php

namespace App\Services\Telegram;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\Money;
use Illuminate\Support\Carbon;

/** Nội dung tin nhắn tóm tắt đầu ngày và báo cáo cuối ngày */
class DailyReports
{
    private const WEEKDAYS = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];

    private const STATUS_ICONS = [
        'pending' => '⏳',
        'confirmed' => '✅',
        'in_progress' => '▶️',
        'completed' => '✔️',
    ];

    public function summary(Carbon $day): string
    {
        $bookings = Booking::with(['customer', 'staff', 'items'])
            ->whereIn('status', [...BookingStatus::blocking(), BookingStatus::Completed])
            ->whereBetween('start_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('start_at')
            ->get();

        $pending = $bookings->where('status', BookingStatus::Pending)->count();
        $lines = ["📅 <b>Lịch {$this->dayLabel($day)}</b>: {$bookings->count()} lịch".($pending ? " ({$pending} chờ duyệt)" : '')];

        if ($bookings->isEmpty()) {
            $lines[] = 'Chưa có lịch hẹn nào.';
        }

        foreach ($bookings->groupBy('staff_id') as $staffBookings) {
            $lines[] = '';
            $lines[] = '💇 <b>'.e($staffBookings->first()->staff->name).'</b>';
            foreach ($staffBookings as $booking) {
                $lines[] = (self::STATUS_ICONS[$booking->status->value] ?? '•').' '.$booking->start_at->format('H:i').' '
                    .e($booking->items->pluck('service_name')->implode(', ')).' · '.e($booking->customer->name);
            }
        }

        return implode("\n", $lines);
    }

    public function report(Carbon $day): string
    {
        $range = [$day->copy()->startOfDay(), $day->copy()->endOfDay()];

        $completed = Booking::with('staff')->where('status', BookingStatus::Completed)->whereBetween('paid_at', $range)->get();
        $dayBookings = Booking::whereBetween('start_at', $range)->get();
        $tomorrow = Booking::blocking()->whereBetween('start_at', [$day->copy()->addDay()->startOfDay(), $day->copy()->addDay()->endOfDay()])->get();

        $lines = [
            "📊 <b>Báo cáo {$this->dayLabel($day)}</b>",
            '',
            '💰 Doanh thu: <b>'.Money::format($completed->sum('total')).'</b> ('.$completed->count().' lịch hoàn thành)',
        ];

        foreach ($completed->groupBy('staff_id') as $staffBookings) {
            $lines[] = '   • '.e($staffBookings->first()->staff->name).': '.Money::format($staffBookings->sum('total')).' ('.$staffBookings->count().')';
        }

        $lines[] = '';
        $lines[] = '🚫 Hủy: '.$dayBookings->where('status', BookingStatus::Cancelled)->count()
            .' · Không đến: '.$dayBookings->where('status', BookingStatus::NoShow)->count()
            .' · Từ chối: '.$dayBookings->where('status', BookingStatus::Rejected)->count();
        $lines[] = '📅 Ngày mai: '.$tomorrow->count().' lịch'
            .(($p = $tomorrow->where('status', BookingStatus::Pending)->count()) ? " ({$p} chờ duyệt)" : '');

        return implode("\n", $lines);
    }

    private function dayLabel(Carbon $day): string
    {
        return self::WEEKDAYS[$day->dayOfWeek].' '.$day->format('d/m');
    }
}
