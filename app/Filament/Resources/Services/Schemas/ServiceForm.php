<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Thông tin dịch vụ')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên dịch vụ')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                        TextInput::make('slug')
                            ->label('Đường dẫn')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('category_id')
                            ->label('Nhóm dịch vụ')
                            ->relationship('category', 'name')
                            ->required()
                            ->preload(),
                        TextInput::make('short_description')
                            ->label('Mô tả ngắn')
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Mô tả chi tiết')
                            ->rows(4)
                            ->columnSpanFull(),
                        FileUpload::make('image')
                            ->label('Ảnh')
                            ->image()
                            ->disk('public')
                            ->directory('services')
                            ->maxSize(4096)
                            ->columnSpanFull(),
                    ]),
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Giá & thời lượng')->schema([
                            TextInput::make('price')
                                ->label('Giá')
                                ->helperText('Giá mặc định; có thể đặt giá riêng theo từng nhân viên.')
                                ->required()
                                ->integer()
                                ->minValue(0)
                                ->step(1000)
                                ->suffix('đ')
                                ->live(onBlur: true),
                            TextInput::make('price_max')
                                ->label('Giá tối đa')
                                ->helperText('Điền nếu muốn hiển thị khoảng giá, vd "200.000đ – 350.000đ".')
                                ->integer()
                                ->step(1000)
                                ->suffix('đ')
                                ->minValue(fn (Get $get) => (int) $get('price')),
                            TextInput::make('duration_minutes')
                                ->label('Thời lượng')
                                ->required()
                                ->integer()
                                ->minValue(5)
                                ->step(5)
                                ->suffix('phút'),
                            TextInput::make('buffer_minutes')
                                ->label('Thời gian dọn dẹp')
                                ->helperText('Thợ vẫn bận sau khi làm xong, khách sau không đặt được vào khoảng này.')
                                ->integer()
                                ->minValue(0)
                                ->default(0)
                                ->suffix('phút'),
                        ]),
                        Section::make('Hiển thị')->schema([
                            Toggle::make('is_active')->label('Đang cung cấp')->default(true),
                            Toggle::make('is_featured')->label('Dịch vụ nổi bật'),
                        ]),
                    ]),
            ]);
    }
}
