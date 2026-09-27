<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\PaymentMethod;
use App\Events\BookingCreated;
use App\Events\BookingRescheduled;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\User;
use App\Services\Voucher\VoucherException;
use App\Services\Voucher\VoucherService;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingService
{
    /** Số lần thử lại transaction khi MySQL báo deadlock */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private AvailabilityService $availability,
        private VoucherService $vouchers,
        private BookingCodeGenerator $codes,
        private BookingSettings $settings,
    ) {}

    /**
     * Tạo booking. Khách đặt => pending (chờ admin duyệt); admin / nhân viên tạo hộ => confirmed.
     *
     * @throws BookingException
     * @throws VoucherException
     */
    public function create(BookingData $data): Booking
    {
        $phone = PhoneNumber::normalize($data->customerPhone);
        $byStaff = $data->createdBy !== null;

        if (! PhoneNumber::isValid($phone)) {
            throw BookingException::invalidPhone();
        }
        if ($data->serviceIds === []) {
            throw BookingException::noServices();
        }
        if (! $byStaff) {
            $this->assertWithinBookingWindow($data->startAt);
        }

        return DB::transaction(function () use ($data, $phone, $byStaff) {
            $customer = $this->resolveCustomer($data, $phone);

            if ($customer->is_blocked && ! $byStaff) {
                throw BookingException::customerBlocked();
            }
            if (! $byStaff && $customer->pendingBookings()->count() >= $this->settings->maxPendingPerPhone()) {
                throw BookingException::tooManyPending($this->settings->maxPendingPerPhone());
            }

            $candidates = $this->availability->eligibleStaff($data->serviceIds, $data->staffId);

            if ($candidates->isEmpty()) {
                throw $data->staffId ? BookingException::staffCannotPerform() : BookingException::slotUnavailable();
            }

            // Khóa mọi thợ ứng viên một lần, theo thứ tự id, để các request đồng thời không khóa chéo nhau
            Staff::whereKey($candidates->modelKeys())->orderBy('id')->lockForUpdate()->get(['id']);

            foreach ($this->orderByWorkload($candidates, $data->startAt) as $staff) {
                ['duration' => $duration, 'buffer' => $buffer] = $this->availability->durationFor($staff, $data->serviceIds);
                $endAt = $data->startAt->copy()->addMinutes($duration);
                $occupiedUntil = $endAt->copy()->addMinutes($buffer);

                $fits = $data->allowOutsideWorkingHours
                    || $this->availability->fitsWorkingHours($staff, $data->startAt, $occupiedUntil);

                if ($fits && $this->availability->isStaffFree($staff, $data->startAt, $occupiedUntil)) {
                    return $this->persist($data, $customer, $staff, $endAt, $occupiedUntil);
                }
            }

            throw BookingException::slotUnavailable();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function confirm(Booking $booking, User $by): Booking
    {
        return $this->transition($booking, BookingStatus::Confirmed, $by, function (Booking $booking) use ($by) {
            $booking->confirmed_at = now();
            $booking->confirmed_by = $by->id;
            $booking->approval_deadline_at = null;
        });
    }

    public function reject(Booking $booking, User $by, ?string $reason = null): Booking
    {
        return $this->transition($booking, BookingStatus::Rejected, $by, function (Booking $booking) use ($reason) {
            $booking->rejected_at = now();
            $booking->status_reason = $reason;
            $booking->approval_deadline_at = null;
        }, $reason);
    }

    /**
     * @param  User|Customer|null  $actor  Customer = khách tự hủy online (bị giới hạn thời gian);
     *                                     User = nhân viên hủy, kể cả khi ghi nhận khách gọi báo hủy
     *
     * @throws BookingException khách tự hủy lịch đã xác nhận khi đã quá sát giờ hẹn
     */
    public function cancel(Booking $booking, CancelledBy $cancelledBy, User|Customer|null $actor = null, ?string $reason = null): Booking
    {
        return $this->transition($booking, BookingStatus::Cancelled, $actor, function (Booking $booking) use ($cancelledBy, $actor, $reason) {
            $deadlineHours = $this->settings->cancelDeadlineHours();

            if ($actor instanceof Customer
                && $booking->status === BookingStatus::Confirmed
                && now()->addHours($deadlineHours)->gt($booking->start_at)) {
                throw BookingException::cancellationWindowClosed($deadlineHours);
            }

            $booking->cancelled_at = now();
            $booking->cancelled_by = $cancelledBy;
            $booking->status_reason = $reason;
            $booking->approval_deadline_at = null;
        }, $reason);
    }

    /** Hệ thống tự hủy booking pending quá hạn duyệt. Trả về false nếu booking đã được xử lý trước đó. */
    public function expire(Booking $booking): bool
    {
        $booking->refresh();

        if ($booking->status !== BookingStatus::Pending
            || ! $booking->approval_deadline_at
            || $booking->approval_deadline_at->isFuture()) {
            return false;
        }

        $this->cancel($booking, CancelledBy::System, null, 'Quá hạn xác nhận');

        return true;
    }

    public function checkIn(Booking $booking, User $by): Booking
    {
        return $this->transition($booking, BookingStatus::InProgress, $by, function (Booking $booking) {
            $booking->checked_in_at = now();
        });
    }

    /** Hoàn thành và ghi nhận thanh toán. Booking chưa check-in sẽ được check-in luôn. */
    public function complete(Booking $booking, User $by, PaymentMethod $paymentMethod): Booking
    {
        return DB::transaction(function () use ($booking, $by, $paymentMethod) {
            if ($booking->fresh()->status === BookingStatus::Confirmed) {
                $booking = $this->checkIn($booking, $by);
            }

            return $this->transition($booking, BookingStatus::Completed, $by, function (Booking $booking) use ($paymentMethod) {
                $booking->completed_at = now();
                $booking->paid_at = now();
                $booking->payment_method = $paymentMethod;
            });
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function markNoShow(Booking $booking, ?User $by = null): Booking
    {
        return $this->transition($booking, BookingStatus::NoShow, $by);
    }

    /**
     * Đổi giờ và / hoặc thợ cho booking chưa diễn ra. Giá giữ nguyên như lúc khách đặt,
     * thời lượng tính lại theo thợ mới.
     *
     * @throws BookingException
     */
    public function reschedule(Booking $booking, Carbon $startAt, ?int $staffId, User $by, bool $allowOutsideWorkingHours = false): Booking
    {
        return DB::transaction(function () use ($booking, $startAt, $staffId, $by, $allowOutsideWorkingHours) {
            $serviceIds = $booking->items()->pluck('service_id')->all();
            $staff = $this->availability->eligibleStaff($serviceIds, $staffId ?? $booking->staff_id)->first()
                ?? throw BookingException::staffCannotPerform();

            // Cùng thứ tự khóa với create(): thợ trước, booking sau => tránh deadlock
            Staff::whereKey($staff->id)->lockForUpdate()->first(['id']);
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($booking->id);

            if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
                throw BookingException::cannotReschedule($booking->status);
            }

            ['duration' => $duration, 'buffer' => $buffer] = $this->availability->durationFor($staff, $serviceIds);
            $endAt = $startAt->copy()->addMinutes($duration);
            $occupiedUntil = $endAt->copy()->addMinutes($buffer);

            $fits = $allowOutsideWorkingHours || $this->availability->fitsWorkingHours($staff, $startAt, $occupiedUntil);
            if (! $fits || ! $this->availability->isStaffFree($staff, $startAt, $occupiedUntil, $booking->id)) {
                throw BookingException::slotUnavailable();
            }

            $previousStartAt = $booking->start_at->copy();
            $previousStaffId = $booking->staff_id;

            $cursor = $startAt->copy();
            foreach ($booking->items as $item) {
                $minutes = $staff->services->firstWhere('id', $item->service_id)->pivot->custom_duration_minutes
                    ?? $staff->services->firstWhere('id', $item->service_id)->duration_minutes;
                $item->update(['duration_minutes' => $minutes, 'start_at' => $cursor->copy(), 'end_at' => $cursor->addMinutes($minutes)->copy()]);
            }

            $booking->update([
                'staff_id' => $staff->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'occupied_until' => $occupiedUntil,
                'is_staff_auto_assigned' => false,
                'reminder_sent_at' => null,
            ]);

            $this->recordHistory($booking, $booking->status, $booking->status, $by,
                "Đổi lịch từ {$previousStartAt->format('H:i d/m/Y')} (thợ #{$previousStaffId})");

            event(new BookingRescheduled($booking, $previousStartAt, $previousStaffId, $by));

            return $booking;
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function persist(BookingData $data, Customer $customer, Staff $staff, Carbon $endAt, Carbon $occupiedUntil): Booking
    {
        $items = [];
        $cursor = $data->startAt->copy();

        foreach (array_values($data->serviceIds) as $i => $serviceId) {
            $service = $staff->services->firstWhere('id', $serviceId);
            $minutes = $service->pivot->custom_duration_minutes ?? $service->duration_minutes;

            $items[] = [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'price' => $service->pivot->custom_price ?? $service->price,
                'discount_amount' => 0,
                'duration_minutes' => $minutes,
                'start_at' => $cursor->copy(),
                'end_at' => $cursor->addMinutes($minutes)->copy(),
                'sort_order' => $i,
            ];
        }

        $quote = $data->voucherCode ? $this->vouchers->quote($data->voucherCode, $items, $customer) : null;
        foreach ($quote?->allocations ?? [] as $index => $amount) {
            $items[$index]['discount_amount'] = $amount;
        }

        $subtotal = array_sum(array_column($items, 'price'));
        $discount = $quote?->discount ?? 0;
        $byStaff = $data->createdBy !== null;
        $status = $byStaff ? BookingStatus::Confirmed : BookingStatus::Pending;

        $booking = Booking::create([
            'code' => $this->codes->generate(),
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'start_at' => $data->startAt,
            'end_at' => $endAt,
            'occupied_until' => $occupiedUntil,
            'status' => $status,
            'approval_deadline_at' => $byStaff ? null : $this->approvalDeadline($data->startAt),
            'source' => $data->source,
            'qr_code_id' => $data->qrCodeId,
            'is_staff_auto_assigned' => $data->staffId === null,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => $subtotal - $discount,
            'voucher_id' => $quote?->voucher->id,
            'customer_note' => $data->customerNote,
            'confirmed_at' => $byStaff ? now() : null,
            'confirmed_by' => $data->createdBy?->id,
            'created_by' => $data->createdBy?->id,
        ]);

        $booking->items()->createMany($items);

        if ($quote) {
            $this->vouchers->redeem($quote, $booking);
        }

        $this->recordHistory($booking, null, $status, $data->createdBy ?? $customer);

        event(new BookingCreated($booking));

        return $booking;
    }

    /**
     * Khóa dòng booking, kiểm tra được phép chuyển trạng thái, áp thay đổi, ghi lịch sử và bắn event (sau commit).
     *
     * @param  (Closure(Booking): void)|null  $apply
     */
    private function transition(Booking $booking, BookingStatus $to, User|Customer|null $actor, ?Closure $apply = null, ?string $note = null): Booking
    {
        return DB::transaction(function () use ($booking, $to, $actor, $apply, $note) {
            $locked = Booking::lockForUpdate()->findOrFail($booking->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw BookingException::invalidTransition($from, $to);
            }

            if ($apply) {
                $apply($locked);
            }
            $locked->status = $to;
            $locked->save();

            match ($to) {
                BookingStatus::Rejected, BookingStatus::Cancelled => $this->vouchers->release($locked),
                BookingStatus::Completed => $this->recordVisit($locked),
                BookingStatus::NoShow => $locked->customer()->increment('no_show_count'),
                default => null,
            };

            $this->recordHistory($locked, $from, $to, $actor, $note);

            event(new BookingStatusChanged($locked, $from, $to, $actor));

            return $locked;
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function recordVisit(Booking $booking): void
    {
        $customer = $booking->customer()->lockForUpdate()->first();

        $customer->forceFill([
            'total_visits' => $customer->total_visits + 1,
            'total_spent' => $customer->total_spent + $booking->total,
            'last_visit_at' => $customer->last_visit_at?->max($booking->start_at) ?? $booking->start_at,
        ])->save();
    }

    private function recordHistory(Booking $booking, ?BookingStatus $from, BookingStatus $to, User|Customer|null $actor, ?string $note = null): void
    {
        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'note' => $note,
        ]);
    }

    /** Tìm khách theo SĐT (tạo mới nếu chưa có) và khóa dòng để đếm lịch pending cho chính xác */
    private function resolveCustomer(BookingData $data, string $phone): Customer
    {
        $customer = Customer::createOrFirst(['phone' => $phone], [
            'name' => $data->customerName,
            'email' => $data->customerEmail,
        ]);

        $customer = Customer::lockForUpdate()->findOrFail($customer->id);

        if (! $customer->email && $data->customerEmail) {
            $customer->update(['email' => $data->customerEmail]);
        }

        return $customer;
    }

    private function assertWithinBookingWindow(Carbon $startAt): void
    {
        $minLead = $this->settings->minLeadMinutes();
        $maxDays = $this->settings->maxAdvanceDays();

        if ($startAt->lt(now()->addMinutes($minLead))) {
            throw BookingException::tooSoon($minLead);
        }
        if ($startAt->gt(today()->addDays($maxDays)->endOfDay())) {
            throw BookingException::tooFarAhead($maxDays);
        }
    }

    /**
     * Khi khách chọn "Bất kỳ ai": ưu tiên thợ có ít lịch nhất trong ngày.
     *
     * @param  EloquentCollection<int, Staff>  $staff
     * @return EloquentCollection<int, Staff>
     */
    private function orderByWorkload(EloquentCollection $staff, Carbon $date): EloquentCollection
    {
        if ($staff->count() < 2) {
            return $staff;
        }

        $load = Booking::blocking()
            ->whereIn('staff_id', $staff->modelKeys())
            ->whereBetween('start_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->groupBy('staff_id')
            ->selectRaw('staff_id, COUNT(*) as total')
            ->pluck('total', 'staff_id');

        return $staff->sortBy([
            fn (Staff $a, Staff $b) => ($load[$a->id] ?? 0) <=> ($load[$b->id] ?? 0),
            fn (Staff $a, Staff $b) => $a->sort_order <=> $b->sort_order,
        ])->values();
    }

    /** min(thời điểm tiệm mở cửa gần nhất + approval_minutes, start_at - approval_min_before_start) */
    private function approvalDeadline(Carbon $startAt): Carbon
    {
        return $this->availability->nextOpeningMoment(now())
            ->addMinutes($this->settings->approvalMinutes())
            ->min($startAt->copy()->subMinutes($this->settings->approvalMinBeforeStart()));
    }
}
