<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Staff;
use App\Services\Reports\RevenueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon(); // hôm nay = 05/10/2026
    }

    /** Lịch đã hoàn thành với tổng tiền / giảm giá / dịch vụ cho trước */
    private function completed(Staff $staff, string $paidAt, int $total, array $options = []): Booking
    {
        $customer = Customer::firstOrCreate(['phone' => $options['phone'] ?? '0900000001'], ['name' => 'Khách']);
        $start = Carbon::parse($paidAt)->subHour();
        $service = $options['service'] ?? $this->haircut;
        $discount = $options['discount'] ?? 0;

        $booking = Booking::create([
            'code' => 'BK'.strtoupper(substr(md5(uniqid()), 0, 6)),
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(30),
            'occupied_until' => $start->copy()->addMinutes(35),
            'status' => $options['status'] ?? BookingStatus::Completed,
            'source' => $options['source'] ?? BookingSource::Web,
            'subtotal' => $total + $discount,
            'discount_amount' => $discount,
            'total' => $total,
            'payment_method' => $options['method'] ?? PaymentMethod::Cash,
            'paid_at' => ($options['status'] ?? BookingStatus::Completed) === BookingStatus::Completed ? $paidAt : null,
        ]);
        $booking->items()->create([
            'service_id' => $service->id, 'service_name' => $service->name,
            'price' => $total + $discount, 'discount_amount' => $discount, 'duration_minutes' => 30,
            'start_at' => $start, 'end_at' => $start->copy()->addMinutes(30),
        ]);

        return $booking;
    }

    public function test_summary_counts_only_completed_bookings_paid_in_the_period(): void
    {
        $this->completed($this->tuan, '2026-10-01 10:00', 100_000, ['discount' => 20_000]);
        $this->completed($this->nam, '2026-10-03 15:00', 200_000, ['phone' => '0900000002']);
        $this->completed($this->nam, '2026-09-20 10:00', 999_000, ['phone' => '0900000002']); // ngoài kỳ
        $this->completed($this->tuan, '2026-10-02 10:00', 500_000, ['status' => BookingStatus::Cancelled, 'phone' => '0900000003']);
        $this->completed($this->tuan, '2026-10-02 12:00', 500_000, ['status' => BookingStatus::NoShow, 'phone' => '0900000003']);

        $summary = (new RevenueReport(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-05')))->summary();

        $this->assertSame(300_000, $summary['revenue']);
        $this->assertSame(2, $summary['completed']);
        $this->assertSame(150_000, $summary['average']);
        $this->assertSame(20_000, $summary['discount']);
        $this->assertSame(2, $summary['customers']);
        $this->assertSame(1, $summary['new_customers']); // khách 0900000002 đã đến từ tháng 9
        $this->assertSame(50.0, $summary['lost_rate']); // 2 hủy/không đến trên 4 lịch đã tới hẹn
    }

    public function test_timeline_fills_empty_days_and_switches_to_months_for_long_periods(): void
    {
        $this->completed($this->tuan, '2026-10-02 10:00', 100_000);
        $this->completed($this->nam, '2026-10-02 18:00', 50_000, ['phone' => '0900000002']);

        $days = (new RevenueReport(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-03')))->timeline();
        $this->assertSame(['01/10', '02/10', '03/10'], array_column($days, 'label'));
        $this->assertSame([0, 150_000, 0], array_column($days, 'revenue'));
        $this->assertSame([0, 2, 0], array_column($days, 'count'));

        $months = (new RevenueReport(Carbon::parse('2026-01-15'), Carbon::parse('2026-10-05')))->timeline();
        $this->assertCount(10, $months);
        $this->assertSame(['label' => '10/2026', 'revenue' => 150_000], ['label' => end($months)['label'], 'revenue' => end($months)['revenue']]);
    }

    public function test_breakdowns_are_sorted_by_revenue(): void
    {
        $this->completed($this->tuan, '2026-10-01 10:00', 100_000, ['method' => PaymentMethod::Cash]);
        $this->completed($this->nam, '2026-10-02 10:00', 300_000, ['phone' => '0900000002', 'source' => BookingSource::Qr, 'method' => PaymentMethod::BankTransfer, 'service' => $this->coloring]);
        $this->completed($this->nam, '2026-10-03 10:00', 80_000, ['phone' => '0900000003', 'discount' => 20_000]);

        $report = new RevenueReport(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-05'));

        $this->assertSame([['label' => 'Nam', 'revenue' => 380_000, 'count' => 2], ['label' => 'Tuấn', 'revenue' => 100_000, 'count' => 1]], $report->byStaff());
        $this->assertSame([['label' => 'Nhuộm', 'revenue' => 300_000, 'count' => 1], ['label' => 'Cắt tóc', 'revenue' => 180_000, 'count' => 2]], $report->byService());
        $this->assertSame(['Mã QR', 'Website'], array_column($report->bySource(), 'label'));
        $this->assertSame(['Chuyển khoản', 'Tiền mặt'], array_column($report->byPaymentMethod(), 'label'));
    }

    public function test_small_services_are_folded_into_other(): void
    {
        foreach (range(1, 5) as $i) {
            $service = $this->makeService("DV {$i}", 10_000 * $i, 30);
            $this->completed($this->tuan, '2026-10-02 10:00', 10_000 * $i, ['service' => $service, 'phone' => "090000000{$i}"]);
        }

        $rows = (new RevenueReport(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-05')))->byService(top: 3);

        $this->assertSame(['DV 5', 'DV 4', 'DV 3', 'Khác (2 dịch vụ)'], array_column($rows, 'label'));
        $this->assertSame(30_000, end($rows)['revenue']);
    }

    public function test_period_presets_and_previous_period(): void
    {
        $this->assertSame('01/09/2026 – 30/09/2026', RevenueReport::fromFilters(['period' => 'last_month'])->label());
        $this->assertSame('29/09/2026 – 05/10/2026', RevenueReport::fromFilters(['period' => '7d'])->label());
        $this->assertSame('01/10/2026 – 05/10/2026', RevenueReport::fromFilters(['period' => 'this_month'])->label());
        $this->assertSame('01/10/2026 – 03/10/2026', RevenueReport::fromFilters(['period' => 'custom', 'from' => '2026-10-03', 'until' => '2026-10-01'])->label());

        $this->assertSame('22/09/2026 – 28/09/2026', RevenueReport::fromFilters(['period' => '7d'])->previous()->label());
        $this->assertSame(50.0, RevenueReport::change(150, 100));
        $this->assertNull(RevenueReport::change(150, 0));
    }
}
