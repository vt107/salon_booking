<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\QrCode;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Booking\BookingCodeGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu demo cho môi trường local: khách, voucher, QR và booking 30 ngày trước + 7 ngày tới.
 */
class DemoSeeder extends Seeder
{
    private User $admin;

    /** @var Collection<int, Customer> */
    private Collection $customers;

    /** @var array<string, Voucher> */
    private array $vouchers;

    /** @var array<string, QrCode> */
    private array $qrCodes;

    public function __construct(private BookingCodeGenerator $codes) {}

    public function run(): void
    {
        $this->admin = User::where('email', 'admin@salon.test')->firstOrFail();
        $this->seedDemoAccounts();
        $this->customers = Customer::factory(60)->create();
        $this->seedVouchersAndQrCodes();

        $staffList = Staff::with(['services', 'schedules'])->get();

        DB::transaction(function () use ($staffList) {
            for ($day = today()->subDays(30); $day->lte(today()->addDays(7)); $day = $day->addDay()) {
                foreach ($staffList as $staff) {
                    $this->seedStaffDay($staff, $day);
                }
            }
        });

        $this->refreshCustomerStats();

        // Lượt quét nhiều hơn số lịch đặt được từ mã đó (không phải ai quét cũng đặt)
        foreach ($this->qrCodes as $qr) {
            $qr->forceFill(['scan_count' => $qr->bookings()->count() * fake()->numberBetween(2, 4)])->save();
        }
    }

    /** Tài khoản thử phân quyền: quản lý (duyệt lịch) và nhân viên gắn với một thợ */
    private function seedDemoAccounts(): void
    {
        User::firstOrCreate(['email' => 'quanly@salon.test'], [
            'name' => 'Chị Lan (quản lý)',
            'password' => 'password',
            'role' => UserRole::Manager,
        ]);

        $staffUser = User::firstOrCreate(['email' => 'nhanvien@salon.test'], [
            'name' => 'Hoàng Nam',
            'password' => 'password',
            'role' => UserRole::Staff,
        ]);
        Staff::where('slug', 'hoang-nam')->update(['user_id' => $staffUser->id]);
    }

    private function seedVouchersAndQrCodes(): void
    {
        $this->vouchers = [
            'welcome' => Voucher::create([
                'code' => 'WELCOME10',
                'name' => 'Giảm 10% cho khách mới',
                'type' => VoucherType::Percent,
                'value' => 10,
                'max_discount' => 50_000,
                'first_booking_only' => true,
                'scope' => VoucherScope::All,
            ]),
            'fixed' => Voucher::create([
                'code' => 'GIAM50K',
                'name' => 'Giảm 50.000đ cho hóa đơn từ 300.000đ',
                'type' => VoucherType::Fixed,
                'value' => 50_000,
                'min_order_amount' => 300_000,
                'usage_limit' => 200,
                'usage_limit_per_customer' => 2,
                'starts_at' => today()->subDays(30),
                'ends_at' => today()->addDays(30)->endOfDay(),
                'scope' => VoucherScope::All,
            ]),
        ];

        $nail = Voucher::create([
            'code' => 'NAIL20',
            'name' => 'Giảm 20% dịch vụ Nail',
            'type' => VoucherType::Percent,
            'value' => 20,
            'max_discount' => 100_000,
            'scope' => VoucherScope::Services,
        ]);
        $nail->services()->sync(Service::whereHas('category', fn ($q) => $q->where('slug', 'nail'))->pluck('id'));

        $this->qrCodes = [
            'counter' => QrCode::create(['code' => 'QUAY01', 'name' => 'Poster quầy lễ tân']),
            'fanpage' => QrCode::create(['code' => 'FANPAGE', 'name' => 'Fanpage Facebook', 'voucher_id' => $this->vouchers['welcome']->id]),
        ];
    }

    private function seedStaffDay(Staff $staff, Carbon $day): void
    {
        $isFuture = $day->isAfter(today());

        foreach ($staff->schedules->where('day_of_week', $day->dayOfWeek) as $shift) {
            $cursor = $day->copy()->setTimeFromTimeString($shift->start_time);
            $shiftEnd = $day->copy()->setTimeFromTimeString($shift->end_time);

            while (true) {
                // Chừa khoảng trống để lịch không kín hoàn toàn (ngày tương lai càng thưa)
                $cursor = $cursor->copy()->addMinutes(fake()->randomElement([0, 0, 15, 30, 45, 60]));

                if (fake()->boolean($isFuture ? 65 : 35)) {
                    $cursor = $cursor->addMinutes(30);

                    if ($cursor->gte($shiftEnd)) {
                        break;
                    }

                    continue;
                }

                $services = $staff->services->random(min(fake()->numberBetween(1, 2), $staff->services->count()));
                $duration = $services->sum(fn (Service $s) => $s->pivot->custom_duration_minutes ?? $s->duration_minutes);
                $occupiedUntil = $cursor->copy()->addMinutes($duration + $services->last()->buffer_minutes);

                if ($occupiedUntil->gt($shiftEnd)) {
                    break;
                }

                $this->createBooking($staff, $services, $cursor->copy());
                $cursor = $occupiedUntil;
            }
        }
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    private function createBooking(Staff $staff, Collection $services, Carbon $startAt): void
    {
        $customer = $this->customers->random();
        $source = fake()->randomElement([
            ...array_fill(0, 11, BookingSource::Web),
            ...array_fill(0, 3, BookingSource::Qr),
            ...array_fill(0, 3, BookingSource::Phone),
            ...array_fill(0, 2, BookingSource::WalkIn),
            BookingSource::Admin,
        ]);

        // Dựng các dòng dịch vụ nối tiếp nhau
        $items = [];
        $cursor = $startAt->copy();
        foreach ($services->values() as $i => $service) {
            $minutes = $service->pivot->custom_duration_minutes ?? $service->duration_minutes;
            $items[] = [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'price' => $service->pivot->custom_price ?? $service->price,
                'discount_amount' => 0,
                'duration_minutes' => $minutes,
                'start_at' => $cursor->copy(),
                'end_at' => $cursor->copy()->addMinutes($minutes),
                'sort_order' => $i,
            ];
            $cursor->addMinutes($minutes);
        }
        $endAt = $cursor;
        $subtotal = array_sum(array_column($items, 'price'));

        $status = $this->pickStatus($startAt, $endAt);
        $createdAt = $status === BookingStatus::Pending
            ? now()->subMinutes(fake()->numberBetween(1, 20))
            : $startAt->copy()->subHours(fake()->numberBetween(2, 96));
        $createdAt = $createdAt->min(now());

        $booking = new Booking;
        $booking->forceFill([
            'code' => $this->codes->generate(),
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'occupied_until' => $endAt->copy()->addMinutes($services->last()->buffer_minutes),
            'status' => $status,
            'source' => $source,
            'qr_code_id' => $source === BookingSource::Qr ? fake()->randomElement($this->qrCodes)->id : null,
            'is_staff_auto_assigned' => fake()->boolean(20),
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'total' => $subtotal,
            'customer_note' => fake()->boolean(15) ? fake()->randomElement(['Da nhạy cảm', 'Muốn tóc ngắn gọn', 'Đi cùng bạn', 'Có thể đến trễ 5 phút']) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'created_by' => in_array($source, [BookingSource::Admin, BookingSource::Phone, BookingSource::WalkIn], true) ? $this->admin->id : null,
            ...$this->statusTimestamps($status, $startAt, $endAt, $createdAt),
        ])->save();

        $booking->items()->createMany($items);
        $this->maybeApplyVoucher($booking);
        $this->writeStatusHistory($booking, $createdAt);
    }

    private function pickStatus(Carbon $startAt, Carbon $endAt): BookingStatus
    {
        if ($startAt->isFuture()) {
            return fake()->boolean(35) ? BookingStatus::Pending : BookingStatus::Confirmed;
        }

        if ($endAt->isFuture()) {
            return BookingStatus::InProgress;
        }

        return fake()->randomElement([
            ...array_fill(0, 40, BookingStatus::Completed),
            ...array_fill(0, 4, BookingStatus::Cancelled),
            ...array_fill(0, 3, BookingStatus::NoShow),
            ...array_fill(0, 3, BookingStatus::Rejected),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusTimestamps(BookingStatus $status, Carbon $startAt, Carbon $endAt, Carbon $createdAt): array
    {
        $approvedAt = $createdAt->copy()->addMinutes(fake()->numberBetween(3, 40))->min($startAt);

        return match ($status) {
            BookingStatus::Pending => [
                'approval_deadline_at' => $createdAt->copy()->addMinutes(60)->min($startAt->copy()->subMinutes(30)),
            ],
            BookingStatus::Confirmed => [
                'confirmed_at' => $approvedAt, 'confirmed_by' => $this->admin->id,
            ],
            BookingStatus::Rejected => [
                'rejected_at' => $approvedAt, 'status_reason' => fake()->randomElement(['Hết chỗ', 'Thợ bận']),
            ],
            BookingStatus::Cancelled => [
                'confirmed_at' => $approvedAt, 'confirmed_by' => $this->admin->id,
                'cancelled_at' => $startAt->copy()->subHours(fake()->numberBetween(3, 24))->max($approvedAt),
                'cancelled_by' => CancelledBy::Customer, 'status_reason' => 'Khách bận đột xuất',
            ],
            BookingStatus::NoShow => [
                'confirmed_at' => $approvedAt, 'confirmed_by' => $this->admin->id,
            ],
            BookingStatus::InProgress => [
                'confirmed_at' => $approvedAt, 'confirmed_by' => $this->admin->id, 'checked_in_at' => $startAt,
            ],
            BookingStatus::Completed => [
                'confirmed_at' => $approvedAt, 'confirmed_by' => $this->admin->id,
                'checked_in_at' => $startAt, 'completed_at' => $endAt, 'paid_at' => $endAt,
                'payment_method' => fake()->randomElement([PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::BankTransfer, PaymentMethod::BankTransfer, PaymentMethod::Card, PaymentMethod::Ewallet]),
            ],
        };
    }

    private function maybeApplyVoucher(Booking $booking): void
    {
        if (! fake()->boolean(15)) {
            return;
        }

        $voucher = $booking->subtotal >= 300_000 ? $this->vouchers['fixed'] : $this->vouchers['welcome'];
        $discount = $voucher->type === VoucherType::Fixed
            ? $voucher->value
            : min(intdiv($booking->subtotal * $voucher->value, 100), $voucher->max_discount);

        // Phân bổ giảm giá theo tỉ lệ giá từng dịch vụ, phần dư dồn vào dịch vụ cuối
        $remaining = $discount;
        $items = $booking->items;
        foreach ($items as $i => $item) {
            $share = $i === $items->count() - 1 ? $remaining : intdiv($discount * $item->price, $booking->subtotal);
            $item->update(['discount_amount' => $share]);
            $remaining -= $share;
        }

        $booking->update([
            'voucher_id' => $voucher->id,
            'discount_amount' => $discount,
            'total' => $booking->subtotal - $discount,
        ]);

        $released = in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Rejected], true);
        $booking->voucherUsage()->create([
            'voucher_id' => $voucher->id,
            'customer_id' => $booking->customer_id,
            'discount_amount' => $discount,
            'released_at' => $released ? ($booking->cancelled_at ?? $booking->rejected_at) : null,
        ]);

        if (! $released) {
            $voucher->increment('used_count');
        }
    }

    private function writeStatusHistory(Booking $booking, Carbon $createdAt): void
    {
        $path = match ($booking->status) {
            BookingStatus::Pending => [BookingStatus::Pending],
            BookingStatus::Confirmed => [BookingStatus::Pending, BookingStatus::Confirmed],
            BookingStatus::Rejected => [BookingStatus::Pending, BookingStatus::Rejected],
            BookingStatus::Cancelled => [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::Cancelled],
            BookingStatus::NoShow => [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::NoShow],
            BookingStatus::InProgress => [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::InProgress],
            BookingStatus::Completed => [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed],
        };

        $at = [
            BookingStatus::Pending->value => $createdAt,
            BookingStatus::Confirmed->value => $booking->confirmed_at,
            BookingStatus::Rejected->value => $booking->rejected_at,
            BookingStatus::Cancelled->value => $booking->cancelled_at,
            BookingStatus::NoShow->value => $booking->start_at->copy()->addMinutes(30),
            BookingStatus::InProgress->value => $booking->checked_in_at,
            BookingStatus::Completed->value => $booking->completed_at,
        ];

        $from = null;
        foreach ($path as $to) {
            [$actorType, $actorId] = match ($to) {
                BookingStatus::Pending, BookingStatus::Cancelled => [$booking->customer->getMorphClass(), $booking->customer_id],
                BookingStatus::NoShow => [null, null],
                default => [$this->admin->getMorphClass(), $this->admin->id],
            };

            $booking->statusHistories()->make()->forceFill([
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'created_at' => $at[$to->value],
            ])->save();
            $from = $to;
        }
    }

    private function refreshCustomerStats(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customers c
            LEFT JOIN (
                SELECT customer_id,
                       SUM(status = 'completed') AS visits,
                       SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) AS spent,
                       SUM(status = 'no_show') AS no_shows,
                       MAX(CASE WHEN status = 'completed' THEN start_at END) AS last_visit
                FROM bookings
                GROUP BY customer_id
            ) s ON s.customer_id = c.id
            SET c.total_visits = COALESCE(s.visits, 0),
                c.total_spent = COALESCE(s.spent, 0),
                c.no_show_count = COALESCE(s.no_shows, 0),
                c.last_visit_at = s.last_visit
        SQL);
    }
}
