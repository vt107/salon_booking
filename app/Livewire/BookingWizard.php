<?php

namespace App\Livewire;

use App\Enums\BookingSource;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingData;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Voucher\VoucherException;
use App\Services\Voucher\VoucherService;
use App\Support\Demo\DemoMode;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Form đặt lịch cho khách: Dịch vụ → Thời gian (ngày, giờ) → Người thợ → Xác nhận.
 * Mọi dữ liệu client gửi lên đều được kiểm tra lại với danh sách hợp lệ; BookingService kiểm tra lần cuối.
 */
class BookingWizard extends Component
{
    public const MAX_SERVICES = 5;

    /** Số lần gửi tối đa mỗi IP trong 10 phút */
    public const MAX_SUBMITS = 5;

    public int $step = 1;

    /** @var list<int> theo thứ tự làm */
    public array $serviceIds = [];

    public ?string $date = null;

    public ?string $time = null;

    /** "any" hoặc id thợ */
    public string $staffChoice = 'any';

    /** Thợ khách chọn từ trước (trang Đội ngũ, mã QR của thợ): lọc dịch vụ và giờ theo thợ này */
    #[Locked]
    public ?int $preferredStaffId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $note = '';

    public string $voucherCode = '';

    /** @var array{code: string, discount: int}|null */
    #[Locked]
    public ?array $voucher = null;

    /** Ô ẩn chống bot */
    public string $website = '';

    #[Locked]
    public ?int $qrCodeId = null;

    public function mount(?int $service = null, ?int $staff = null, ?string $voucher = null): void
    {
        $this->qrCodeId = session('booking.qr_code_id');

        if ($staff && Staff::bookable()->whereKey($staff)->exists()) {
            $this->preferredStaffId = $staff;
            $this->staffChoice = (string) $staff;
        }
        if ($service && $this->categories->flatMap->services->contains('id', $service)) {
            $this->serviceIds = [$service];
        }
        $this->voucherCode = (string) $voucher;
    }

    public function toggleService(int $id): void
    {
        if (in_array($id, $this->serviceIds, true)) {
            $this->serviceIds = array_values(array_diff($this->serviceIds, [$id]));
        } elseif (count($this->serviceIds) < self::MAX_SERVICES && $this->categories->flatMap->services->contains('id', $id)) {
            $this->serviceIds[] = $id;
        }

        $this->date = null;
        $this->resetTime();
    }

    public function clearPreferredStaff(): void
    {
        $this->preferredStaffId = null;
        $this->staffChoice = 'any';
        $this->date = null;
        unset($this->categories);
        $this->resetTime();
    }

    public function selectDate(string $date): void
    {
        if (in_array($date, $this->availableDates, true)) {
            $this->date = $date;
            $this->resetTime();
        }
    }

    public function selectTime(string $time): void
    {
        if (array_key_exists($time, $this->timeSlots)) {
            $this->time = $time;
            $this->staffChoice = $this->preferredStaffId ? (string) $this->preferredStaffId : 'any';
            $this->voucher = null;
            $this->resetErrorBag('time');
            unset($this->staffOptions, $this->lineItems);
        }
    }

    public function selectStaff(string $choice): void
    {
        if ($choice === 'any' || $this->staffOptions->contains('id', (int) $choice)) {
            $this->staffChoice = $choice;
            $this->voucher = null;
            unset($this->lineItems);
        }
    }

    public function next(): void
    {
        $ok = match ($this->step) {
            1 => $this->serviceIds !== [],
            2 => $this->date && $this->time && array_key_exists($this->time, $this->timeSlots),
            3 => $this->staffChoice === 'any' || $this->staffOptions->contains('id', (int) $this->staffChoice),
            default => false,
        };

        if ($ok) {
            $this->step++;
        }
    }

    public function goTo(int $step): void
    {
        if ($step >= 1 && $step < $this->step) {
            $this->step = $step;
        }
    }

    public function applyVoucher(): void
    {
        $this->resetErrorBag('voucherCode');
        $this->voucher = null;

        if (trim($this->voucherCode) === '') {
            return;
        }

        try {
            $quote = app(VoucherService::class)->quote($this->voucherCode, $this->lineItems, $this->existingCustomer());
            $this->voucher = ['code' => $quote->voucher->code, 'discount' => $quote->discount];
        } catch (VoucherException $e) {
            $this->addError('voucherCode', $e->getMessage());
        }
    }

    public function removeVoucher(): void
    {
        $this->voucher = null;
        $this->voucherCode = '';
    }

    public function submit(): void
    {
        if ($this->step !== 4) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', function (string $attribute, $value, \Closure $fail) {
                if (! PhoneNumber::isValid((string) $value)) {
                    $fail('Số điện thoại không hợp lệ.');
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: ['name' => 'họ tên', 'phone' => 'số điện thoại', 'note' => 'ghi chú']);

        if ($this->website !== '') {
            $this->addError('form', 'Không gửi được yêu cầu, vui lòng thử lại.');

            return;
        }

        // Bản demo: kiểm tra form như thật rồi dừng trước khi tính lượt gửi
        DemoMode::abortIfEnabled();

        $key = 'booking-submit:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_SUBMITS)) {
            $this->addError('form', 'Bạn thao tác quá nhiều lần, vui lòng thử lại sau ít phút hoặc gọi cho tiệm.');

            return;
        }
        RateLimiter::hit($key, 600);

        try {
            $booking = app(BookingService::class)->create(new BookingData(
                customerName: trim($this->name),
                customerPhone: $this->phone,
                serviceIds: $this->serviceIds,
                startAt: $this->startAt(),
                staffId: $this->staffChoice === 'any' ? null : (int) $this->staffChoice,
                customerEmail: trim($this->email) ?: null,
                customerNote: trim($this->note) ?: null,
                voucherCode: $this->voucher['code'] ?? null,
                source: $this->qrCodeId ? BookingSource::Qr : BookingSource::Web,
                qrCodeId: $this->qrCodeId,
            ));
        } catch (BookingException $e) {
            if ($e->getMessage() === BookingException::slotUnavailable()->getMessage()) {
                // Khung giờ vừa bị người khác đặt: quay lại chọn giờ
                $this->step = 2;
                $this->resetTime();
                $this->addError('time', $e->getMessage());

                return;
            }
            $this->addError('form', $e->getMessage());

            return;
        } catch (VoucherException $e) {
            $this->voucher = null;
            $this->addError('voucherCode', $e->getMessage());

            return;
        }

        session()->forget('booking.qr_code_id');
        session()->flash('booking.just_created', true);

        $this->redirect($booking->manageUrl());
    }

    /** @return EloquentCollection<int, ServiceCategory> */
    #[Computed]
    public function categories(): EloquentCollection
    {
        $staffServiceIds = $this->preferredStaffId
            ? Staff::find($this->preferredStaffId)?->services()->pluck('services.id')->all() ?? []
            : null;
        $filter = fn ($q) => $q->active()->orderBy('sort_order')
            ->when($staffServiceIds !== null, fn ($q) => $q->whereIn('services.id', $staffServiceIds));

        return ServiceCategory::where('is_active', true)
            ->with(['services' => $filter])
            ->whereHas('services', $filter)
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Service> theo thứ tự khách chọn */
    #[Computed]
    public function selectedServices(): Collection
    {
        $services = $this->categories->flatMap->services->keyBy('id');

        return collect($this->serviceIds)->map(fn (int $id) => $services[$id] ?? null)->filter()->values();
    }

    /** @return list<string> */
    #[Computed]
    public function availableDates(): array
    {
        return $this->serviceIds
            ? app(AvailabilityService::class)->availableDates($this->serviceIds, $this->preferredStaffId)
            : [];
    }

    /**
     * Không đặt tên "slots": trùng tính năng slot có sẵn của Livewire 4.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function timeSlots(): array
    {
        return $this->date && $this->serviceIds
            ? app(AvailabilityService::class)->slotsForDate(Carbon::parse($this->date), $this->serviceIds, $this->preferredStaffId)
            : [];
    }

    /** @return EloquentCollection<int, Staff> */
    #[Computed]
    public function staffOptions(): EloquentCollection
    {
        return $this->date && $this->time
            ? app(AvailabilityService::class)->staffAvailableAt($this->startAt(), $this->serviceIds)
            : new EloquentCollection;
    }

    /**
     * Các dòng dịch vụ và giá theo thợ đã chọn (chọn "Bất kỳ ai" thì dùng giá niêm yết).
     *
     * @return list<array{service_id: int, name: string, price: int, duration: int}>
     */
    #[Computed]
    public function lineItems(): array
    {
        $staff = $this->staffChoice === 'any' ? null : $this->staffOptions->firstWhere('id', (int) $this->staffChoice);

        return $this->selectedServices->map(function (Service $service) use ($staff) {
            $pivot = $staff?->services->firstWhere('id', $service->id)?->pivot;

            return [
                'service_id' => $service->id,
                'name' => $service->name,
                'price' => $pivot?->custom_price ?? $service->price,
                'duration' => $pivot?->custom_duration_minutes ?? $service->duration_minutes,
            ];
        })->all();
    }

    /** Khoảng giá giữa các thợ đang rảnh khi khách chọn "Bất kỳ ai" */
    #[Computed]
    public function priceRange(): ?array
    {
        if ($this->staffChoice !== 'any' || $this->staffOptions->isEmpty()) {
            return null;
        }

        $totals = $this->staffOptions->map(fn (Staff $staff) => $this->selectedServices->sum(
            fn (Service $service) => $staff->services->firstWhere('id', $service->id)?->pivot->custom_price ?? $service->price
        ));

        return $totals->min() !== $totals->max() ? [$totals->min(), $totals->max()] : null;
    }

    public function render()
    {
        return view('livewire.booking-wizard', [
            'subtotal' => array_sum(array_column($this->lineItems, 'price')),
            'duration' => array_sum(array_column($this->lineItems, 'duration')),
            'discount' => $this->voucher['discount'] ?? 0,
            'preferredStaff' => $this->preferredStaffId ? Staff::find($this->preferredStaffId) : null,
        ]);
    }

    private function startAt(): Carbon
    {
        return Carbon::parse("{$this->date} {$this->time}");
    }

    private function resetTime(): void
    {
        $this->time = null;
        $this->staffChoice = $this->preferredStaffId ? (string) $this->preferredStaffId : 'any';
        $this->voucher = null;
        unset($this->availableDates, $this->timeSlots, $this->staffOptions, $this->lineItems, $this->selectedServices, $this->priceRange);
    }

    private function existingCustomer(): ?Customer
    {
        return PhoneNumber::isValid($this->phone)
            ? Customer::firstWhere('phone', PhoneNumber::normalize($this->phone))
            : null;
    }
}
