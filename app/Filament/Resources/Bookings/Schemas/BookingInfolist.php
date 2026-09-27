<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Enums\BookingStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Lịch hẹn')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('status')->label('Trạng thái')->badge(),
                            TextEntry::make('time')
                                ->label('Thời gian')
                                ->state(fn (Booking $record) => $record->start_at->format('H:i').' – '.$record->end_at->format('H:i'))
                                ->helperText(fn (Booking $record) => Str::ucfirst($record->start_at->translatedFormat('l, d/m/Y'))),
                            TextEntry::make('staff.name')
                                ->label('Nhân viên')
                                ->helperText(fn (Booking $record) => $record->is_staff_auto_assigned ? 'Khách chọn "Bất kỳ ai", hệ thống tự gán' : null),
                            TextEntry::make('approval_deadline_at')
                                ->label('Hạn duyệt')
                                ->dateTime('H:i d/m/Y')
                                ->color('warning')
                                ->visible(fn (Booking $record) => $record->status === BookingStatus::Pending),
                            TextEntry::make('status_reason')
                                ->label('Lý do')
                                ->visible(fn (Booking $record) => filled($record->status_reason)),
                            TextEntry::make('source')->label('Nguồn')->badge()->color('gray'),
                            TextEntry::make('created_at')
                                ->label('Đặt lúc')
                                ->dateTime('H:i d/m/Y')
                                ->helperText(fn (Booking $record) => $record->creator ? "Tạo bởi {$record->creator->name}" : 'Khách tự đặt'),
                        ]),
                    Section::make('Dịch vụ')
                        ->schema([
                            RepeatableEntry::make('items')
                                ->hiddenLabel()
                                ->table([
                                    RepeatableEntry\TableColumn::make('Dịch vụ'),
                                    RepeatableEntry\TableColumn::make('Giờ'),
                                    RepeatableEntry\TableColumn::make('Giá'),
                                    RepeatableEntry\TableColumn::make('Giảm'),
                                ])
                                ->schema([
                                    TextEntry::make('service_name'),
                                    TextEntry::make('start_at')->formatStateUsing(fn ($state, $record) => $record->start_at->format('H:i').' – '.$record->end_at->format('H:i')),
                                    TextEntry::make('price')->formatStateUsing(fn (int $state) => Money::format($state)),
                                    TextEntry::make('discount_amount')->formatStateUsing(fn (int $state) => $state ? '-'.Money::format($state) : '—'),
                                ]),
                            Grid::make(4)->schema([
                                TextEntry::make('subtotal')->label('Tạm tính')->formatStateUsing(fn (int $state) => Money::format($state)),
                                TextEntry::make('discount_amount')
                                    ->label('Giảm giá')
                                    ->formatStateUsing(fn (int $state) => $state ? '-'.Money::format($state) : '—')
                                    ->helperText(fn (Booking $record) => $record->voucher?->code),
                                TextEntry::make('total')->label('Tổng cộng')->weight('bold')->formatStateUsing(fn (int $state) => Money::format($state)),
                                TextEntry::make('payment_method')
                                    ->label('Thanh toán')
                                    ->placeholder('Chưa thanh toán')
                                    ->helperText(fn (Booking $record) => $record->paid_at?->format('H:i d/m/Y')),
                            ]),
                        ]),
                    Section::make('Ghi chú')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('customer_note')->label('Khách ghi chú')->placeholder('—'),
                            TextEntry::make('internal_note')->label('Ghi chú nội bộ')->placeholder('—'),
                        ]),
                ]),
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Khách hàng')
                        ->schema([
                            TextEntry::make('customer.name')
                                ->label('Tên')
                                ->weight('bold')
                                ->url(fn (Booking $record) => CustomerResource::getUrl('view', ['record' => $record->customer_id])),
                            TextEntry::make('customer.phone')
                                ->label('Điện thoại')
                                ->copyable()
                                ->url(fn (Booking $record) => 'tel:'.$record->customer->phone),
                            TextEntry::make('customer.email')->label('Email')->placeholder('—'),
                            TextEntry::make('customer_stats')
                                ->label('Lịch sử')
                                ->state(fn (Booking $record) => "{$record->customer->total_visits} lần đến · ".Money::format($record->customer->total_spent)),
                            TextEntry::make('customer.no_show_count')
                                ->label('Không đến')
                                ->state(fn (Booking $record) => "{$record->customer->no_show_count} lần")
                                ->color('danger')
                                ->visible(fn (Booking $record) => $record->customer->no_show_count > 0),
                            TextEntry::make('customer.note')->label('Ghi chú về khách')->visible(fn (Booking $record) => filled($record->customer->note)),
                        ]),
                    Section::make('Lịch sử thay đổi')
                        ->schema([
                            RepeatableEntry::make('statusHistories')
                                ->hiddenLabel()
                                ->contained(false)
                                ->schema([
                                    TextEntry::make('to_status')
                                        ->hiddenLabel()
                                        ->badge()
                                        ->helperText(fn (BookingStatusHistory $record) => $record->created_at->format('H:i d/m/Y')
                                            .' · '.($record->actor?->name ?? 'Hệ thống')
                                            .($record->note ? " · {$record->note}" : '')),
                                ]),
                        ]),
                ]),
            ]);
    }
}
