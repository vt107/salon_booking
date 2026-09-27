<?php

namespace App\Filament\Resources\Staff\Schemas;

use App\Support\Weekday;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Thông tin')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên hiển thị')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                        TextInput::make('slug')
                            ->label('Đường dẫn')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('title')
                            ->label('Chức danh')
                            ->placeholder('Senior Stylist, Nail Artist...')
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->maxLength(20),
                        Textarea::make('bio')
                            ->label('Giới thiệu')
                            ->rows(3)
                            ->columnSpanFull(),
                        FileUpload::make('avatar')
                            ->label('Ảnh đại diện')
                            ->image()
                            ->avatar()
                            ->disk('public')
                            ->directory('staff'),
                    ]),
                Section::make('Trạng thái')
                    ->columnSpan(1)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Đang làm việc')
                            ->default(true),
                        Toggle::make('is_bookable')
                            ->label('Nhận lịch online')
                            ->helperText('Tắt nếu thợ chỉ làm cho khách tại tiệm.')
                            ->default(true),
                        Select::make('user_id')
                            ->label('Tài khoản đăng nhập admin')
                            ->relationship('user', 'name')
                            ->unique(ignoreRecord: true)
                            ->searchable()
                            ->preload()
                            ->helperText('Không bắt buộc.'),
                    ]),
                Section::make('Lịch làm việc hằng tuần')
                    ->description('Ca gãy (nghỉ trưa) thì thêm 2 ca trong cùng một ngày. Ngày không có ca là ngày nghỉ.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('schedules')
                            ->hiddenLabel()
                            ->relationship()
                            ->table([
                                TableColumn::make('Thứ'),
                                TableColumn::make('Bắt đầu'),
                                TableColumn::make('Kết thúc'),
                            ])
                            ->schema([
                                Select::make('day_of_week')
                                    ->label('Thứ')
                                    ->options(Weekday::options())
                                    ->required(),
                                TimePicker::make('start_time')
                                    ->label('Bắt đầu')
                                    ->seconds(false)
                                    ->required(),
                                TimePicker::make('end_time')
                                    ->label('Kết thúc')
                                    ->seconds(false)
                                    ->required()
                                    ->rule(fn (Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                                        if ($value && $get('start_time') && $value <= $get('start_time')) {
                                            $fail('Giờ kết thúc phải sau giờ bắt đầu.');
                                        }
                                    }),
                            ])
                            ->default(fn () => collect(Weekday::options())->keys()
                                ->map(fn (int $day) => ['day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '18:00'])
                                ->all())
                            ->addActionLabel('Thêm ca')
                            ->reorderable(false),
                    ]),
            ]);
    }
}
