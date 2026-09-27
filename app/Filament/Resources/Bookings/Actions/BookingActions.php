<?php

namespace App\Filament\Resources\Bookings\Actions;

use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Các thao tác trên booking, dùng chung cho bảng danh sách, trang chi tiết và widget dashboard.
 * Mọi thay đổi trạng thái đi qua BookingService.
 */
class BookingActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::confirm(),
            self::reject(),
            self::checkIn(),
            self::complete(),
            self::reschedule(),
            self::noShow(),
            self::cancel(),
            self::editNote(),
        ];
    }

    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label('Xác nhận')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Booking $record) => $record->status === BookingStatus::Pending && auth()->user()->can('approve', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Booking $record) => "Xác nhận lịch {$record->code}?")
            ->modalDescription('Khách sẽ nhận email xác nhận.')
            ->action(fn (Booking $record, Action $action) => self::run($action, fn (BookingService $bookings) => $bookings->confirm($record, auth()->user()), 'Đã xác nhận lịch hẹn'));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Từ chối')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Booking $record) => $record->status === BookingStatus::Pending && auth()->user()->can('approve', $record))
            ->modalHeading(fn (Booking $record) => "Từ chối lịch {$record->code}")
            ->schema([
                Select::make('reason')
                    ->label('Lý do (khách sẽ thấy)')
                    ->options(self::presetOptions(['Hết chỗ vào khung giờ này', 'Thợ bận đột xuất', 'Tiệm nghỉ vào ngày này', 'Khác']))
                    ->required()
                    ->live(),
                TextInput::make('detail')
                    ->label('Chi tiết')
                    ->required(fn (Get $get) => $get('reason') === 'Khác')
                    ->maxLength(200),
            ])
            ->action(fn (Booking $record, array $data, Action $action) => self::run(
                $action,
                fn (BookingService $bookings) => $bookings->reject($record, auth()->user(), self::reason($data)),
                'Đã từ chối lịch hẹn',
            ));
    }

    public static function checkIn(): Action
    {
        return Action::make('checkIn')
            ->label('Khách đã đến')
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('primary')
            ->visible(fn (Booking $record) => $record->status === BookingStatus::Confirmed && $record->start_at->isToday())
            ->requiresConfirmation()
            ->action(fn (Booking $record, Action $action) => self::run($action, fn (BookingService $bookings) => $bookings->checkIn($record, auth()->user()), 'Đã check-in'));
    }

    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Hoàn thành & thu tiền')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Booking $record) => in_array($record->status, [BookingStatus::Confirmed, BookingStatus::InProgress], true)
                && $record->start_at->lte(now()->endOfDay()))
            ->modalHeading(fn (Booking $record) => 'Thu '.Money::format($record->total))
            ->schema([
                Radio::make('payment_method')
                    ->label('Hình thức thanh toán')
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Cash->value)
                    ->required(),
            ])
            ->action(fn (Booking $record, array $data, Action $action) => self::run(
                $action,
                fn (BookingService $bookings) => $bookings->complete($record, auth()->user(), $data['payment_method'] instanceof PaymentMethod ? $data['payment_method'] : PaymentMethod::from($data['payment_method'])),
                'Đã hoàn thành lịch hẹn',
            ));
    }

    public static function noShow(): Action
    {
        return Action::make('noShow')
            ->label('Khách không đến')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('gray')
            ->visible(fn (Booking $record) => $record->status === BookingStatus::Confirmed && $record->start_at->isPast())
            ->requiresConfirmation()
            ->modalDescription('Số lần không đến của khách sẽ tăng thêm 1.')
            ->action(fn (Booking $record, Action $action) => self::run($action, fn (BookingService $bookings) => $bookings->markNoShow($record, auth()->user()), 'Đã đánh dấu khách không đến'));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Hủy lịch')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Booking $record) => in_array($record->status, [BookingStatus::Pending, BookingStatus::Confirmed], true))
            ->modalHeading(fn (Booking $record) => "Hủy lịch {$record->code}")
            ->schema([
                Radio::make('cancelled_by')
                    ->label('Ai hủy?')
                    ->options([
                        CancelledBy::Customer->value => 'Khách báo hủy',
                        CancelledBy::Staff->value => 'Tiệm hủy',
                    ])
                    ->default(CancelledBy::Customer->value)
                    ->required(),
                TextInput::make('reason')
                    ->label('Lý do')
                    ->maxLength(200),
            ])
            ->action(fn (Booking $record, array $data, Action $action) => self::run(
                $action,
                fn (BookingService $bookings) => $bookings->cancel($record, CancelledBy::from($data['cancelled_by']), auth()->user(), $data['reason'] ?: null),
                'Đã hủy lịch hẹn',
            ));
    }

    public static function reschedule(): Action
    {
        return Action::make('reschedule')
            ->label('Đổi giờ / thợ')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Booking $record) => in_array($record->status, [BookingStatus::Pending, BookingStatus::Confirmed], true))
            ->fillForm(fn (Booking $record) => [
                'staff_id' => $record->staff_id,
                'date' => $record->start_at->toDateString(),
                'time' => $record->start_at->format('H:i'),
            ])
            ->schema(fn (Booking $record) => [
                Select::make('staff_id')
                    ->label('Nhân viên')
                    ->options(fn () => app(AvailabilityService::class)
                        ->eligibleStaff($record->items()->pluck('service_id')->all())
                        ->pluck('name', 'id'))
                    ->required()
                    ->live(),
                DatePicker::make('date')
                    ->label('Ngày')
                    ->required()
                    ->live(),
                Toggle::make('outside_hours')
                    ->label('Xếp ngoài giờ làm / khung không trống')
                    ->live(),
                Select::make('time')
                    ->label('Giờ')
                    ->options(fn (Get $get) => self::slotOptions($record, $get))
                    ->required()
                    ->hidden(fn (Get $get) => $get('outside_hours'))
                    ->helperText(fn (Get $get) => self::slotOptions($record, $get) ? null : 'Thợ không còn khung trống trong ngày này.'),
                TimePicker::make('manual_time')
                    ->label('Giờ')
                    ->seconds(false)
                    ->required()
                    ->visible(fn (Get $get) => $get('outside_hours')),
            ])
            ->action(function (Booking $record, array $data, Action $action) {
                $startAt = Carbon::parse($data['date'].' '.($data['outside_hours'] ? $data['manual_time'] : $data['time']));

                self::run(
                    $action,
                    fn (BookingService $bookings) => $bookings->reschedule($record, $startAt, (int) $data['staff_id'], auth()->user(), (bool) $data['outside_hours']),
                    'Đã đổi lịch hẹn',
                );
            });
    }

    public static function editNote(): Action
    {
        return Action::make('editNote')
            ->label('Ghi chú nội bộ')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->fillForm(fn (Booking $record) => ['internal_note' => $record->internal_note])
            ->schema([
                Textarea::make('internal_note')->label('Ghi chú (khách không thấy)')->rows(4),
            ])
            ->action(fn (Booking $record, array $data) => $record->update(['internal_note' => $data['internal_note']]));
    }

    public static function confirmBulk(): BulkAction
    {
        return BulkAction::make('confirmSelected')
            ->label('Xác nhận các lịch đã chọn')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn () => auth()->user()->canApproveBookings())
            ->requiresConfirmation()
            ->action(function (Collection $records) {
                $bookings = app(BookingService::class);
                $pending = $records->filter(fn (Booking $record) => $record->status === BookingStatus::Pending);
                $failed = 0;

                foreach ($pending as $record) {
                    try {
                        $bookings->confirm($record, auth()->user());
                    } catch (BookingException) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->success()
                    ->title('Đã xác nhận '.($pending->count() - $failed).' lịch hẹn')
                    ->body($records->count() > $pending->count() ? 'Các lịch không ở trạng thái chờ duyệt được bỏ qua.' : null)
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Chạy thao tác nghiệp vụ; lỗi nghiệp vụ hiển thị thành thông báo và giữ nguyên modal.
     *
     * @param  Closure(BookingService): mixed  $callback
     */
    private static function run(Action $action, Closure $callback, string $successMessage): void
    {
        try {
            $callback(app(BookingService::class));
        } catch (BookingException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title($successMessage)->send();
    }

    /** @return array<string, string> */
    private static function slotOptions(Booking $record, Get $get): array
    {
        if (! $get('staff_id') || ! $get('date')) {
            return [];
        }

        $times = array_keys(app(AvailabilityService::class)->slotsForDate(
            Carbon::parse($get('date')),
            $record->items()->pluck('service_id')->all(),
            (int) $get('staff_id'),
            ignoreLeadTime: true,
            ignoreBookingId: $record->id,
        ));

        return array_combine($times, $times);
    }

    /**
     * @param  list<string>  $presets
     * @return array<string, string>
     */
    private static function presetOptions(array $presets): array
    {
        return array_combine($presets, $presets);
    }

    /** @param  array{reason: string, detail?: ?string}  $data */
    private static function reason(array $data): string
    {
        $detail = trim($data['detail'] ?? '');

        return match (true) {
            $data['reason'] === 'Khác' => $detail,
            $detail !== '' => "{$data['reason']}: {$detail}",
            default => $data['reason'],
        };
    }
}
