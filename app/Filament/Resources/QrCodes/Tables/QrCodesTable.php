<?php

namespace App\Filament\Resources\QrCodes\Tables;

use App\Enums\BookingStatus;
use App\Models\QrCode;
use App\Services\Qr\QrImage;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class QrCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['staff', 'service', 'voucher'])
                ->withCount('bookings')
                ->withSum(['bookings as revenue' => fn ($q) => $q->where('status', BookingStatus::Completed)], 'total'))
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->state(fn (QrCode $record) => app(QrImage::class)->dataUri($record, 120))
                    ->square()
                    ->size(56),
                TextColumn::make('name')
                    ->label('Mã QR')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (QrCode $record) => $record->url()),
                TextColumn::make('prefill')
                    ->label('Điền sẵn')
                    ->state(fn (QrCode $record) => collect([
                        $record->staff?->name,
                        $record->service?->name,
                        $record->voucher ? 'Voucher '.$record->voucher->code : null,
                    ])->filter()->implode(' · ') ?: '—')
                    ->wrap(),
                TextColumn::make('scan_count')->label('Lượt quét')->sortable()->alignEnd(),
                TextColumn::make('bookings_count')
                    ->label('Lịch đặt')
                    ->sortable()
                    ->alignEnd()
                    ->description(fn (QrCode $record) => $record->scan_count
                        ? round($record->bookings_count / $record->scan_count * 100).'% lượt quét'
                        : null),
                TextColumn::make('revenue')
                    ->label('Doanh thu mang lại')
                    ->sortable()
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->default(0),
                ToggleColumn::make('is_active')->label('Đang dùng'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('show')
                    ->label('Xem')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (QrCode $record) => $record->name)
                    ->modalContent(fn (QrCode $record): View => view('filament.qr-preview', [
                        'image' => app(QrImage::class)->dataUri($record, 480),
                        'url' => $record->url(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalWidth('md'),
                ActionGroup::make([
                    Action::make('png')
                        ->label('Tải PNG để in')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn (QrCode $record) => response()->streamDownload(
                            fn () => print (app(QrImage::class)->png($record)),
                            "qr-{$record->code}.png",
                            ['Content-Type' => 'image/png'],
                        )),
                    Action::make('svg')
                        ->label('Tải SVG (thiết kế)')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn (QrCode $record) => response()->streamDownload(
                            fn () => print (app(QrImage::class)->svg($record)),
                            "qr-{$record->code}.svg",
                            ['Content-Type' => 'image/svg+xml'],
                        )),
                    EditAction::make(),
                    DeleteAction::make()
                        ->modalDescription('Các mã đã in sẽ chỉ mở trang đặt lịch bình thường. Lịch đã đặt vẫn giữ nguồn "Mã QR".'),
                ]),
            ])
            ->emptyStateHeading('Chưa có mã QR nào')
            ->emptyStateDescription('Tạo mã cho poster ở quầy, fanpage hoặc danh thiếp của từng thợ để biết khách đến từ đâu.');
    }
}
