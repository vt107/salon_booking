<?php

namespace App\Services\Reports;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\BookingItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Số liệu doanh thu trong một khoảng ngày.
 *
 * Doanh thu = tổng bookings.total của lịch "Hoàn thành", tính theo ngày thanh toán (paid_at).
 * Doanh thu theo dịch vụ = giá snapshot trong booking_items trừ phần giảm giá đã phân bổ.
 */
class RevenueReport
{
    public const PERIODS = [
        '7d' => '7 ngày qua',
        '30d' => '30 ngày qua',
        'this_month' => 'Tháng này',
        'last_month' => 'Tháng trước',
        'this_year' => 'Năm nay',
        'custom' => 'Tùy chọn',
    ];

    public readonly Carbon $from;

    public readonly Carbon $to;

    public function __construct(Carbon $from, Carbon $to)
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->endOfDay();
    }

    /** @param  array<string, mixed>|null  $filters  bộ lọc của trang Doanh thu */
    public static function fromFilters(?array $filters): self
    {
        $today = today();

        [$from, $to] = match ($filters['period'] ?? '30d') {
            '7d' => [$today->copy()->subDays(6), $today],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->copy()->startOfYear(), $today],
            'custom' => [
                Carbon::parse($filters['from'] ?? $today->copy()->subDays(29)),
                Carbon::parse($filters['until'] ?? $today),
            ],
            default => [$today->copy()->subDays(29), $today],
        };

        return $from->gt($to) ? new self($to, $from) : new self($from, $to);
    }

    /** Kỳ liền trước, cùng độ dài, để so sánh */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to) + 1;

        return new self($this->from->copy()->subDays($days), $this->from->copy()->subDay());
    }

    public function label(): string
    {
        return $this->from->format('d/m/Y').' – '.$this->to->format('d/m/Y');
    }

    /**
     * @return array{revenue: int, completed: int, average: int, discount: int, customers: int, new_customers: int,
     *               resolved: int, cancelled: int, no_show: int, lost_rate: float|null}
     */
    public function summary(): array
    {
        $completed = $this->completed()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(discount_amount), 0) as discount, COUNT(DISTINCT customer_id) as customers')
            ->first();

        // Khách mới = lần hoàn thành đầu tiên của họ nằm trong kỳ
        $newCustomers = DB::query()
            ->fromSub(
                Booking::where('status', BookingStatus::Completed)->groupBy('customer_id')->selectRaw('customer_id, MIN(paid_at) as first_paid'),
                'firsts',
            )
            ->whereBetween('first_paid', [$this->from, $this->to])
            ->count();

        // Lịch đã tới giờ hẹn trong kỳ: hoàn thành, hủy hoặc không đến
        $outcomes = Booking::whereBetween('start_at', [$this->from, $this->to])
            ->whereIn('status', [BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::NoShow])
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $resolved = (int) $outcomes->sum();
        $lost = (int) (($outcomes['cancelled'] ?? 0) + ($outcomes['no_show'] ?? 0));

        return [
            'revenue' => (int) $completed->revenue,
            'completed' => (int) $completed->n,
            'average' => $completed->n ? intdiv((int) $completed->revenue, (int) $completed->n) : 0,
            'discount' => (int) $completed->discount,
            'customers' => (int) $completed->customers,
            'new_customers' => $newCustomers,
            'resolved' => $resolved,
            'cancelled' => (int) ($outcomes['cancelled'] ?? 0),
            'no_show' => (int) ($outcomes['no_show'] ?? 0),
            'lost_rate' => $resolved ? round($lost / $resolved * 100, 1) : null,
        ];
    }

    /** Theo ngày nếu kỳ ≤ 62 ngày, dài hơn thì theo tháng */
    public function groupsByMonth(): bool
    {
        return $this->from->diffInDays($this->to) > 62;
    }

    /**
     * Doanh thu theo ngày / tháng, có cả những ngày bằng 0.
     *
     * @return list<array{key: string, label: string, revenue: int, count: int}>
     */
    public function timeline(): array
    {
        $byMonth = $this->groupsByMonth();
        $format = $byMonth ? '%Y-%m' : '%Y-%m-%d';

        $rows = $this->completed()
            ->selectRaw("DATE_FORMAT(paid_at, '{$format}') as bucket, SUM(total) as revenue, COUNT(*) as n")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];
        for ($cursor = $this->from->copy(); $cursor->lte($this->to); $byMonth ? $cursor->addMonthNoOverflow()->startOfMonth() : $cursor->addDay()) {
            $key = $cursor->format($byMonth ? 'Y-m' : 'Y-m-d');
            $points[] = [
                'key' => $key,
                'label' => $cursor->format($byMonth ? 'm/Y' : 'd/m'),
                'revenue' => (int) ($rows[$key]->revenue ?? 0),
                'count' => (int) ($rows[$key]->n ?? 0),
            ];
        }

        return $points;
    }

    /** @return list<array{label: string, revenue: int, count: int}> */
    public function byStaff(): array
    {
        return $this->completed()
            ->join('staff', 'staff.id', '=', 'bookings.staff_id')
            ->selectRaw('staff.name as label, SUM(bookings.total) as revenue, COUNT(*) as n')
            ->groupBy('staff.id', 'staff.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'revenue' => (int) $row->revenue, 'count' => (int) $row->n])
            ->all();
    }

    /**
     * Dịch vụ doanh thu cao nhất; phần còn lại gộp thành "Khác".
     *
     * @return list<array{label: string, revenue: int, count: int}>
     */
    public function byService(int $top = 8): array
    {
        $rows = BookingItem::query()
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->where('bookings.status', BookingStatus::Completed)
            ->whereBetween('bookings.paid_at', [$this->from, $this->to])
            ->selectRaw('booking_items.service_id, MAX(booking_items.service_name) as label, SUM(booking_items.price - booking_items.discount_amount) as revenue, COUNT(*) as n')
            ->groupBy('booking_items.service_id')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'revenue' => (int) $row->revenue, 'count' => (int) $row->n]);

        if ($rows->count() <= $top + 1) {
            return $rows->all();
        }

        $rest = $rows->slice($top);

        return [...$rows->take($top)->all(), ['label' => 'Khác ('.$rest->count().' dịch vụ)', 'revenue' => $rest->sum('revenue'), 'count' => $rest->sum('count')]];
    }

    /** @return list<array{label: string, revenue: int, count: int}> */
    public function bySource(): array
    {
        return $this->completed()
            ->selectRaw('source, SUM(total) as revenue, COUNT(*) as n')
            ->groupBy('source')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'label' => BookingSource::from($row->getRawOriginal('source'))->getLabel(),
                'revenue' => (int) $row->revenue,
                'count' => (int) $row->n,
            ])
            ->all();
    }

    /** @return list<array{label: string, revenue: int, count: int}> */
    public function byPaymentMethod(): array
    {
        return $this->completed()
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method, SUM(total) as revenue, COUNT(*) as n')
            ->groupBy('payment_method')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'label' => PaymentMethod::from($row->getRawOriginal('payment_method'))->getLabel(),
                'revenue' => (int) $row->revenue,
                'count' => (int) $row->n,
            ])
            ->all();
    }

    /** % thay đổi so với giá trị kỳ trước (null nếu kỳ trước bằng 0) */
    public static function change(int|float $current, int|float $previous): ?float
    {
        return $previous ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    private function completed(): Builder
    {
        return Booking::query()
            ->where('bookings.status', BookingStatus::Completed)
            ->whereBetween('bookings.paid_at', [$this->from, $this->to]);
    }
}
