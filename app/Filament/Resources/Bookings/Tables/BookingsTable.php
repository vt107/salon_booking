<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\Actions\BookingActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'staff', 'items']))
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->weight('bold')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('start_at')
                    ->label('Thời gian')
                    ->dateTime('H:i d/m/Y')
                    ->description(fn (Booking $record) => Str::ucfirst($record->start_at->translatedFormat('l')).' · '.$record->start_at->diffForHumans())
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Khách hàng')
                    ->description(fn (Booking $record) => $record->customer->phone)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('customer', fn (Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))),
                TextColumn::make('services')
                    ->label('Dịch vụ · Nhân viên')
                    ->state(fn (Booking $record) => $record->items->pluck('service_name')->implode(', '))
                    ->description(fn (Booking $record) => $record->staff->name.($record->is_staff_auto_assigned ? ' (tự gán)' : ''))
                    ->wrap()
                    ->grow(),
                TextColumn::make('total')
                    ->label('Tổng tiền')
                    ->formatStateUsing(fn (int $state) => Money::format($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->description(fn (Booking $record) => $record->status === BookingStatus::Pending && $record->approval_deadline_at
                        ? 'Hạn duyệt '.$record->approval_deadline_at->format('H:i d/m')
                        : null),
                TextColumn::make('source')
                    ->label('Nguồn')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Đặt lúc')
                    ->dateTime('H:i d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('start_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(BookingStatus::class)
                    ->multiple(),
                SelectFilter::make('staff_id')
                    ->label('Nhân viên')
                    ->relationship('staff', 'name'),
                SelectFilter::make('source')
                    ->label('Nguồn')
                    ->options(BookingSource::class),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn (Builder $q, string $date) => $q->where('start_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, string $date) => $q->where('start_at', '<', now()->parse($date)->addDay()))),
            ])
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                BookingActions::confirm()->iconButton()->tooltip('Xác nhận'),
                BookingActions::reject()->iconButton()->tooltip('Từ chối'),
                ActionGroup::make([
                    ViewAction::make(),
                    BookingActions::checkIn(),
                    BookingActions::complete(),
                    BookingActions::reschedule(),
                    BookingActions::noShow(),
                    BookingActions::cancel(),
                ]),
            ])
            ->toolbarActions([
                BookingActions::confirmBulk(),
            ]);
    }
}
