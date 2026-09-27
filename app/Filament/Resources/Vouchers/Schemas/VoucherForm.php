<?php

namespace App\Filament\Resources\Vouchers\Schemas;

use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Voucher;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class VoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Thông tin')
                    ->schema([
                        TextInput::make('code')
                            ->label('Mã')
                            ->required()
                            ->maxLength(32)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->suffixAction(Action::make('generate')
                                ->icon('heroicon-o-sparkles')
                                ->tooltip('Tạo mã ngẫu nhiên')
                                ->action(fn (Set $set) => $set('code', Str::upper(Str::random(8))))),
                        TextInput::make('name')
                            ->label('Tên chương trình')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')->label('Mô tả')->rows(2),
                        Toggle::make('is_active')->label('Đang áp dụng')->default(true),
                    ]),
                Section::make('Mức giảm')
                    ->schema([
                        Radio::make('type')
                            ->label('Kiểu giảm')
                            ->options(VoucherType::class)
                            ->default(VoucherType::Percent)
                            ->inline()
                            ->required()
                            ->live(),
                        TextInput::make('value')
                            ->label('Giá trị')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(fn (Get $get) => self::isPercent($get) ? 100 : null)
                            ->suffix(fn (Get $get) => self::isPercent($get) ? '%' : 'đ'),
                        TextInput::make('max_discount')
                            ->label('Giảm tối đa')
                            ->integer()
                            ->minValue(0)
                            ->suffix('đ')
                            ->visible(fn (Get $get) => self::isPercent($get)),
                        TextInput::make('min_order_amount')
                            ->label('Áp dụng cho đơn từ')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('đ'),
                        Radio::make('scope')
                            ->label('Áp dụng cho')
                            ->options(VoucherScope::class)
                            ->default(VoucherScope::All)
                            ->required()
                            ->live(),
                        Select::make('services')
                            ->label('Dịch vụ được giảm')
                            ->relationship('services', 'name')
                            ->multiple()
                            ->preload()
                            ->required()
                            ->visible(fn (Get $get) => self::scopeIs($get, VoucherScope::Services)),
                    ]),
                Section::make('Điều kiện')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        DateTimePicker::make('starts_at')->label('Bắt đầu')->seconds(false),
                        DateTimePicker::make('ends_at')->label('Kết thúc')->seconds(false)->after('starts_at'),
                        Toggle::make('first_booking_only')->label('Chỉ cho lần đặt đầu tiên')->inline(false),
                        TextInput::make('usage_limit')
                            ->label('Tổng số lượt')
                            ->integer()
                            ->minValue(fn (?Voucher $record) => max(1, $record?->used_count ?? 0))
                            ->placeholder('Không giới hạn'),
                        TextInput::make('usage_limit_per_customer')
                            ->label('Số lượt mỗi khách')
                            ->integer()
                            ->minValue(1)
                            ->placeholder('Không giới hạn'),
                        TextInput::make('used_count')
                            ->label('Đã dùng')
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                    ]),
            ]);
    }

    private static function isPercent(Get $get): bool
    {
        $type = $get('type');

        return ($type instanceof VoucherType ? $type : VoucherType::tryFrom((string) $type)) === VoucherType::Percent;
    }

    private static function scopeIs(Get $get, VoucherScope $scope): bool
    {
        $value = $get('scope');

        return ($value instanceof VoucherScope ? $value : VoucherScope::tryFrom((string) $value)) === $scope;
    }
}
