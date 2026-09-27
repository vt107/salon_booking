<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Enums\BookingSource;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Booking\AvailabilityService;
use App\Support\Money;
use App\Support\PhoneNumber;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Form admin / lễ tân tạo lịch hộ khách (gọi điện, khách vãng lai). Booking tạo ra được xác nhận luôn.
 */
class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Khách hàng')
                    ->schema([
                        TextInput::make('customer_phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->required()
                            ->default(fn () => request()->query('phone'))
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (! PhoneNumber::isValid((string) $value)) {
                                    $fail('Số điện thoại không hợp lệ.');
                                }
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                $customer = $state ? Customer::firstWhere('phone', PhoneNumber::normalize($state)) : null;

                                if ($customer) {
                                    $set('customer_name', $customer->name);
                                    $set('customer_email', $customer->email);
                                }
                            })
                            ->hint(fn (Get $get) => self::customerHint($get('customer_phone')))
                            ->hintColor(fn (Get $get) => self::isRiskyCustomer($get('customer_phone')) ? 'danger' : 'success'),
                        TextInput::make('customer_name')
                            ->label('Tên khách')
                            ->required()
                            ->default(fn () => self::findCustomer(request()->query('phone'))?->name)
                            ->maxLength(255),
                        TextInput::make('customer_email')
                            ->label('Email')
                            ->email()
                            ->helperText('Có email thì khách nhận được thông báo và nhắc lịch.'),
                        Select::make('source')
                            ->label('Nguồn')
                            ->options([
                                BookingSource::Phone->value => BookingSource::Phone->getLabel(),
                                BookingSource::WalkIn->value => BookingSource::WalkIn->getLabel(),
                                BookingSource::Admin->value => 'Khác',
                            ])
                            ->default(BookingSource::Phone->value)
                            ->required(),
                    ]),
                Section::make('Lịch hẹn')
                    ->schema([
                        Select::make('service_ids')
                            ->label('Dịch vụ (theo thứ tự làm)')
                            ->multiple()
                            ->options(fn () => Service::active()
                                ->with('category')
                                ->orderBy('sort_order')
                                ->get()
                                ->groupBy(fn (Service $s) => $s->category->name)
                                ->map(fn ($services) => $services->mapWithKeys(fn (Service $s) => [
                                    $s->id => "{$s->name} · ".Money::format($s->price)." · {$s->duration_minutes}'",
                                ])->all())
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('time', null)),
                        Select::make('staff_id')
                            ->label('Nhân viên')
                            ->placeholder('Bất kỳ ai (tự gán người rảnh)')
                            ->options(fn (Get $get) => $get('service_ids')
                                ? app(AvailabilityService::class)->eligibleStaff(array_map('intval', $get('service_ids')))->pluck('name', 'id')
                                : [])
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('time', null)),
                        DatePicker::make('date')
                            ->label('Ngày')
                            ->default(today())
                            ->minDate(today())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('time', null)),
                        Toggle::make('outside_hours')
                            ->label('Xếp ngoài giờ làm của thợ')
                            ->helperText('Chỉ dùng khi đã thống nhất với thợ. Vẫn không được trùng lịch khác.')
                            ->live(),
                        Select::make('time')
                            ->label('Giờ')
                            ->options(fn (Get $get) => self::slotOptions($get))
                            ->required()
                            ->hidden(fn (Get $get) => $get('outside_hours'))
                            ->helperText(fn (Get $get) => $get('service_ids') && ! self::slotOptions($get)
                                ? 'Không còn khung trống trong ngày này.'
                                : null),
                        TimePicker::make('manual_time')
                            ->label('Giờ')
                            ->seconds(false)
                            ->required()
                            ->visible(fn (Get $get) => $get('outside_hours')),
                        Text::make(fn (Get $get) => self::summary($get)),
                    ]),
                Section::make('Khác')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('voucher_code')->label('Mã giảm giá'),
                        Textarea::make('customer_note')->label('Ghi chú của khách')->rows(2),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    private static function slotOptions(Get $get): array
    {
        if (! $get('service_ids') || ! $get('date')) {
            return [];
        }

        $slots = app(AvailabilityService::class)->slotsForDate(
            Carbon::parse($get('date')),
            array_map('intval', $get('service_ids')),
            $get('staff_id') ? (int) $get('staff_id') : null,
            ignoreLeadTime: true,
        );

        return collect($slots)
            ->mapWithKeys(fn (array $staffIds, string $time) => [$time => $get('staff_id') ? $time : "{$time} (".count($staffIds).' thợ rảnh)'])
            ->all();
    }

    private static function summary(Get $get): string
    {
        $services = Service::whereKey($get('service_ids') ?? [])->get();

        if ($services->isEmpty()) {
            return '';
        }

        return 'Giá niêm yết: '.Money::format($services->sum('price')).' · Thời lượng: '.$services->sum('duration_minutes').' phút';
    }

    private static function customerHint(?string $phone): ?string
    {
        $customer = self::findCustomer($phone);

        if (! $customer) {
            return $phone ? 'Khách mới' : null;
        }

        return "Khách quen · {$customer->total_visits} lần đến"
            .($customer->no_show_count ? " · {$customer->no_show_count} lần không đến" : '')
            .($customer->is_blocked ? ' · ĐANG BỊ CHẶN ĐẶT ONLINE' : '');
    }

    private static function isRiskyCustomer(?string $phone): bool
    {
        $customer = self::findCustomer($phone);

        return $customer && ($customer->no_show_count > 0 || $customer->is_blocked);
    }

    private static function findCustomer(?string $phone): ?Customer
    {
        return $phone && PhoneNumber::isValid($phone)
            ? Customer::firstWhere('phone', PhoneNumber::normalize($phone))
            : null;
    }
}
