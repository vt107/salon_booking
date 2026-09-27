<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Liên hệ')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('phone')->label('Điện thoại')->copyable()->url(fn (Customer $record) => 'tel:'.$record->phone),
                        TextEntry::make('email')->label('Email')->placeholder('—'),
                        TextEntry::make('gender')->label('Giới tính')->placeholder('—'),
                        TextEntry::make('birthday')->label('Sinh nhật')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('note')->label('Ghi chú')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Thống kê')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('total_visits')->label('Số lần đến')->suffix(' lần'),
                        TextEntry::make('total_spent')->label('Tổng chi tiêu')->formatStateUsing(fn (int $state) => Money::format($state)),
                        TextEntry::make('last_visit_at')->label('Lần gần nhất')->dateTime('d/m/Y')->placeholder('Chưa đến lần nào'),
                        TextEntry::make('no_show_count')
                            ->label('Đặt mà không đến')
                            ->suffix(' lần')
                            ->color(fn (int $state) => $state > 0 ? 'danger' : null),
                        TextEntry::make('is_blocked')
                            ->label('Đặt online')
                            ->formatStateUsing(fn (bool $state) => $state ? 'Đang bị chặn' : 'Bình thường')
                            ->badge()
                            ->color(fn (bool $state) => $state ? 'danger' : 'success'),
                        TextEntry::make('created_at')->label('Khách từ')->date('d/m/Y'),
                    ]),
            ]);
    }
}
