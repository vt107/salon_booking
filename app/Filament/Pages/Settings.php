<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\BusinessHour;
use App\Models\ClosedDay;
use App\Models\Setting;
use App\Services\Booking\BookingSettings;
use App\Support\Weekday;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Cấu hình vận hành của tiệm: thông tin, giờ mở cửa, ngày nghỉ, quy tắc đặt lịch, Telegram.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Cài đặt';

    private const SHOP_KEYS = ['name', 'address', 'phone', 'email', 'logo', 'map_url', 'facebook', 'zalo'];

    private const TELEGRAM_KEYS = ['group_chat_id', 'approval_reminder_minutes', 'daily_summary_time', 'daily_report_time'];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->role->isAdmin();
    }

    public function mount(): void
    {
        $hours = BusinessHour::all()->keyBy('day_of_week');

        $this->form->fill([
            'shop' => collect(self::SHOP_KEYS)->mapWithKeys(fn ($key) => [$key => Setting::get("shop.{$key}")])->all(),
            'booking' => collect(BookingSettings::DEFAULTS)->map(fn ($default, $key) => Setting::get("booking.{$key}", $default))->all(),
            'telegram' => collect(self::TELEGRAM_KEYS)->mapWithKeys(fn ($key) => [$key => Setting::get("telegram.{$key}")])->all(),
            'hours' => collect(Weekday::options())->mapWithKeys(fn ($label, int $day) => [$day => [
                'is_open' => ! ($hours[$day]->is_closed ?? false),
                'open_time' => $hours[$day]->open_time ?? '08:30',
                'close_time' => $hours[$day]->close_time ?? '20:30',
            ]])->all(),
            'closed_days' => ClosedDay::where('date', '>=', today()->subMonth())
                ->orderBy('date')
                ->get()
                ->map(fn (ClosedDay $day) => ['date' => $day->date->toDateString(), 'reason' => $day->reason])
                ->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Thông tin tiệm')->schema($this->shopFields()),
                        Tab::make('Giờ mở cửa & ngày nghỉ')->schema($this->openingHoursFields()),
                        Tab::make('Quy tắc đặt lịch')->schema($this->bookingFields()),
                        Tab::make('Telegram')->schema($this->telegramFields()),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Lưu cài đặt')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        DB::transaction(function () use ($data) {
            foreach (['shop' => self::SHOP_KEYS, 'telegram' => self::TELEGRAM_KEYS, 'booking' => array_keys(BookingSettings::DEFAULTS)] as $group => $keys) {
                foreach ($keys as $key) {
                    $value = $data[$group][$key] ?? null;
                    Setting::set("{$group}.{$key}", $group === 'booking' ? (int) $value : $value);
                }
            }

            foreach ($data['hours'] as $day => $hours) {
                BusinessHour::updateOrCreate(['day_of_week' => $day], [
                    'is_closed' => ! $hours['is_open'],
                    'open_time' => $hours['open_time'] ?? null,
                    'close_time' => $hours['close_time'] ?? null,
                ]);
            }

            // Chỉ đồng bộ các ngày đang hiển thị (từ 1 tháng trước), không đụng dữ liệu cũ hơn
            $dates = collect($data['closed_days'])->pluck('date')->all();
            ClosedDay::where('date', '>=', today()->subMonth())->whereNotIn('date', $dates)->delete();
            foreach ($data['closed_days'] as $day) {
                ClosedDay::updateOrCreate(['date' => $day['date']], ['reason' => $day['reason'] ?? null]);
            }
        });

        Notification::make()->success()->title('Đã lưu cài đặt')->send();
    }

    /** @return list<mixed> */
    private function shopFields(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('shop.name')->label('Tên tiệm')->required()->maxLength(100),
                TextInput::make('shop.phone')->label('Hotline')->tel(),
                TextInput::make('shop.address')->label('Địa chỉ')->columnSpanFull(),
                TextInput::make('shop.email')->label('Email')->email(),
                TextInput::make('shop.map_url')->label('Link Google Maps')->url(),
                TextInput::make('shop.facebook')->label('Facebook')->url(),
                TextInput::make('shop.zalo')->label('Zalo')->placeholder('SĐT hoặc link Zalo OA'),
                FileUpload::make('shop.logo')->label('Logo')->image()->disk('public')->directory('shop'),
            ]),
        ];
    }

    /** @return list<mixed> */
    private function openingHoursFields(): array
    {
        $days = collect(Weekday::options())->map(fn (string $label, int $day) => Grid::make(4)->schema([
            Toggle::make("hours.{$day}.is_open")->label($label)->inline(false)->live(),
            TimePicker::make("hours.{$day}.open_time")
                ->label('Mở cửa')
                ->seconds(false)
                ->required(fn (Get $get) => $get("hours.{$day}.is_open"))
                ->visible(fn (Get $get) => $get("hours.{$day}.is_open")),
            TimePicker::make("hours.{$day}.close_time")
                ->label('Đóng cửa')
                ->seconds(false)
                ->required(fn (Get $get) => $get("hours.{$day}.is_open"))
                ->visible(fn (Get $get) => $get("hours.{$day}.is_open"))
                ->rule(fn (Get $get) => function (string $attribute, $value, Closure $fail) use ($get, $day) {
                    if ($value && $value <= $get("hours.{$day}.open_time")) {
                        $fail('Giờ đóng cửa phải sau giờ mở cửa.');
                    }
                }),
        ]))->values()->all();

        return [
            Section::make('Giờ mở cửa')
                ->description('Ca làm của thợ bị cắt theo giờ mở cửa. Tắt một ngày = tiệm nghỉ cố định ngày đó.')
                ->schema($days),
            Section::make('Ngày nghỉ (lễ, Tết, nghỉ đột xuất)')
                ->description('Không nhận lịch vào các ngày này. Lịch đã đặt không tự hủy, cần xử lý trong mục Lịch hẹn.')
                ->schema([
                    Repeater::make('closed_days')
                        ->hiddenLabel()
                        ->schema([
                            DatePicker::make('date')->label('Ngày')->required()->distinct(),
                            TextInput::make('reason')->label('Lý do')->maxLength(255),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Thêm ngày nghỉ')
                        ->reorderable(false),
                ]),
        ];
    }

    /** @return list<mixed> */
    private function bookingFields(): array
    {
        $number = fn (string $key, string $label, string $suffix, string $help, int $min = 0) => TextInput::make("booking.{$key}")
            ->label($label)
            ->helperText($help)
            ->required()
            ->integer()
            ->minValue($min)
            ->suffix($suffix);

        return [
            Grid::make(2)->schema([
                $number('slot_interval_minutes', 'Bước khung giờ', 'phút', 'Khung giờ hiển thị cho khách: 15 => 09:00, 09:15, 09:30...', 5),
                $number('min_lead_minutes', 'Đặt trước tối thiểu', 'phút', 'Khách không đặt được khung giờ sắp tới gần hơn mức này.'),
                $number('max_advance_days', 'Đặt trước tối đa', 'ngày', 'Chỉ mở lịch trong số ngày tới.', 1),
                $number('max_pending_per_phone', 'Số lịch chờ duyệt mỗi SĐT', 'lịch', 'Chống giữ chỗ ảo.', 1),
                $number('approval_minutes', 'Thời gian để duyệt', 'phút', 'Tính từ lúc tiệm mở cửa; quá hạn chưa duyệt thì lịch tự hủy.', 5),
                $number('approval_min_before_start', 'Phải duyệt trước giờ hẹn', 'phút', 'Hạn duyệt không được muộn hơn giờ hẹn trừ đi mức này.'),
                $number('cancel_deadline_hours', 'Khách tự hủy trước', 'giờ', 'Sau mốc này khách phải gọi điện để hủy.'),
                $number('reminder_hours_before', 'Nhắc lịch trước', 'giờ', 'Email nhắc khách trước giờ hẹn.', 1),
                $number('no_show_grace_minutes', 'Chờ khách trễ', 'phút', 'Quá giờ hẹn mức này mà chưa check-in thì tự đánh dấu "không đến".', 5),
            ]),
        ];
    }

    /** @return list<mixed> */
    private function telegramFields(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('telegram.group_chat_id')
                    ->label('Chat ID của group')
                    ->helperText('Group Telegram của tiệm nhận thông báo lịch mới. Để trống nếu chỉ gửi riêng cho từng admin.')
                    ->regex('/^-?\d+$/'),
                TextInput::make('telegram.approval_reminder_minutes')
                    ->label('Nhắc duyệt trước hạn')
                    ->integer()
                    ->minValue(1)
                    ->suffix('phút'),
                TimePicker::make('telegram.daily_summary_time')->label('Gửi lịch trong ngày lúc')->seconds(false),
                TimePicker::make('telegram.daily_report_time')->label('Gửi báo cáo cuối ngày lúc')->seconds(false),
            ]),
        ];
    }
}
