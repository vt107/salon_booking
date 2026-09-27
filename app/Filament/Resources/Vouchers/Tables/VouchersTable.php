<?php

namespace App\Filament\Resources\Vouchers\Tables;

use App\Enums\VoucherType;
use App\Models\Voucher;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class VouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->weight('bold')
                    ->copyable()
                    ->searchable()
                    ->description(fn (Voucher $record) => $record->name),
                TextColumn::make('value')
                    ->label('Mức giảm')
                    ->formatStateUsing(fn (Voucher $record) => $record->type === VoucherType::Percent
                        ? "{$record->value}%".($record->max_discount ? ' (tối đa '.Money::format($record->max_discount).')' : '')
                        : Money::format($record->value))
                    ->description(fn (Voucher $record) => $record->min_order_amount ? 'Đơn từ '.Money::format($record->min_order_amount) : null),
                TextColumn::make('used_count')
                    ->label('Đã dùng')
                    ->formatStateUsing(fn (Voucher $record) => $record->used_count.($record->usage_limit ? " / {$record->usage_limit}" : ''))
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Hiệu lực')
                    ->formatStateUsing(fn (Voucher $record) => ($record->starts_at?->format('d/m/Y') ?? '…').' → '.($record->ends_at?->format('d/m/Y') ?? '…'))
                    ->color(fn (Voucher $record) => $record->ends_at?->isPast() ? 'danger' : null)
                    ->placeholder('Không thời hạn'),
                ToggleColumn::make('is_active')->label('Đang áp dụng'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }
}
