<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\BusinessHour;
use App\Models\ClosedDay;
use App\Models\Setting;
use App\Services\Booking\BookingSettings;
use App\Services\Telegram\TelegramConfig;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramSetup;
use App\Support\SiteSettings;
use App\Support\Weekday;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Cấu hình của tiệm: thông tin, website & SEO, giờ mở cửa, quy tắc đặt lịch, bot Telegram.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Cài đặt';

    private const SHOP_KEYS = ['name', 'address', 'phone', 'email', 'logo', 'map_url', 'facebook', 'zalo', 'instagram', 'tiktok'];

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
        $site = app(SiteSettings::class);

        $this->form->fill([
            'shop' => collect(self::SHOP_KEYS)->mapWithKeys(fn ($key) => [$key => Setting::get("shop.{$key}")])->all(),
            'site' => collect(SiteSettings::DEFAULTS)->map(fn ($default, $key) => $site->get($key))->all(),
            'booking' => collect(BookingSettings::DEFAULTS)->map(fn ($default, $key) => Setting::get("booking.{$key}", $default))->all(),
            'telegram' => collect(self::TELEGRAM_KEYS)->mapWithKeys(fn ($key) => [$key => Setting::get("telegram.{$key}")])->all(),
            // Không bao giờ đưa token đã lưu ra trình duyệt
            'bot' => ['token' => null],
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
                        Tab::make('Thông tin tiệm')->icon(Heroicon::OutlinedBuildingStorefront)->schema($this->shopFields()),
                        Tab::make('Website & SEO')->icon(Heroicon::OutlinedGlobeAlt)->schema($this->websiteFields()),
                        Tab::make('Giờ mở cửa & ngày nghỉ')->icon(Heroicon::OutlinedClock)->schema($this->openingHoursFields()),
                        Tab::make('Quy tắc đặt lịch')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->schema($this->bookingFields()),
                        Tab::make('Telegram')->icon(Heroicon::OutlinedChatBubbleLeftRight)->schema($this->telegramFields()),
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

        // Token mới: kiểm tra với Telegram trước khi lưu bất cứ thứ gì
        $newToken = trim((string) ($data['bot']['token'] ?? ''));
        $bot = null;
        if ($newToken !== '') {
            try {
                $bot = app(TelegramSetup::class)->verify($newToken);
            } catch (TelegramException $e) {
                throw ValidationException::withMessages(['data.bot.token' => 'Telegram không nhận token này: '.$e->getMessage()]);
            }
        }

        DB::transaction(function () use ($data, $newToken, $bot) {
            foreach (['shop' => self::SHOP_KEYS, 'telegram' => self::TELEGRAM_KEYS, 'booking' => array_keys(BookingSettings::DEFAULTS), 'site' => array_keys(SiteSettings::DEFAULTS)] as $group => $keys) {
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

            if ($bot) {
                app(TelegramConfig::class)->saveBot($newToken, $bot['username']);
            }
        });

        $this->data['bot']['token'] = null;
        Notification::make()->success()->title('Đã lưu cài đặt')->send();

        if ($bot) {
            $this->afterBotSaved($bot['username']);
        }
    }

    /** Bot mới: server có HTTPS thì bật webhook luôn, máy dev thì hướng dẫn chạy polling */
    private function afterBotSaved(string $username): void
    {
        $setup = app(TelegramSetup::class);

        if (! $setup->webhookUrl()) {
            Notification::make()->info()->title("Đã kết nối bot @{$username}")
                ->body('Máy chưa có HTTPS công khai: chạy "make telegram" để bot nhận tin nhắn.')
                ->persistent()->send();

            return;
        }

        try {
            $setup->enableWebhook();
            Notification::make()->success()->title("Bot @{$username} đã sẵn sàng")->body('Webhook đã được bật.')->send();
        } catch (TelegramException $e) {
            Notification::make()->warning()->title('Chưa bật được webhook')->body($e->getMessage())->persistent()->send();
        }
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
            ]),
            Section::make('Mạng xã hội')
                ->description('Hiện ở chân trang website. Để trống mục nào thì ẩn mục đó.')
                ->columns(2)
                ->schema([
                    TextInput::make('shop.facebook')->label('Facebook')->url()->placeholder('https://facebook.com/...'),
                    TextInput::make('shop.zalo')->label('Zalo')->placeholder('SĐT hoặc link Zalo OA'),
                    TextInput::make('shop.instagram')->label('Instagram')->url()->placeholder('https://instagram.com/...'),
                    TextInput::make('shop.tiktok')->label('TikTok')->url()->placeholder('https://tiktok.com/@...'),
                ]),
        ];
    }

    /** @return list<mixed> */
    private function websiteFields(): array
    {
        $seo = fn (string $page, string $label) => Grid::make(2)->schema([
            TextInput::make("site.{$page}_title")
                ->label("Tiêu đề {$label}")
                ->maxLength(70)
                ->helperText('Hiện trên tab trình duyệt và kết quả Google. Nên dưới 60 ký tự.'),
            Textarea::make("site.{$page}_description")
                ->label("Mô tả {$label}")
                ->rows(2)
                ->maxLength(170)
                ->helperText('Đoạn mô tả dưới tiêu đề trên Google. Nên 120–160 ký tự.'),
        ]);

        return [
            Section::make('Nhận diện')
                ->columns(3)
                ->schema([
                    FileUpload::make('shop.logo')
                        ->label('Logo')
                        ->helperText('Hiện cạnh tên tiệm ở đầu trang. Ảnh vuông, nền trong suốt.')
                        ->image()->disk('public')->directory('site')->maxSize(2048),
                    FileUpload::make('site.favicon')
                        ->label('Favicon')
                        ->helperText('Biểu tượng nhỏ trên tab trình duyệt. PNG vuông, tối thiểu 64×64.')
                        ->image()->disk('public')->directory('site')->maxSize(512),
                    FileUpload::make('site.og_image')
                        ->label('Ảnh khi chia sẻ link')
                        ->helperText('Hiện khi gửi link web qua Facebook, Zalo. Tỉ lệ 1200×630.')
                        ->image()->disk('public')->directory('site')->maxSize(4096),
                    ColorPicker::make('site.accent_color')
                        ->label('Màu nhấn')
                        ->helperText('Màu nút "Đặt lịch", chữ nhấn mạnh. Để trống = màu đất nung mặc định.')
                        ->placeholder(SiteSettings::DEFAULT_ACCENT)
                        ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                            if ($value && ! SiteSettings::accentIsReadable($value)) {
                                $fail('Màu này quá nhạt: chữ trắng trên nút sẽ khó đọc. Hãy chọn màu đậm hơn.');
                            }
                        }),
                    TextInput::make('site.site_title')
                        ->label('Tên hiển thị trên tab trình duyệt')
                        ->placeholder('Mặc định là tên tiệm')
                        ->maxLength(60),
                ]),
            Section::make('SEO')
                ->description('Để trống sẽ dùng nội dung tự động theo tên tiệm.')
                ->schema([
                    $seo('home', 'trang chủ'),
                    $seo('prices', 'trang bảng giá'),
                    $seo('team', 'trang đội ngũ'),
                    Toggle::make('site.noindex')
                        ->label('Ẩn website khỏi Google')
                        ->helperText('Bật khi web đang chạy thử, chưa muốn khách tìm thấy.'),
                ]),
            Section::make('Nội dung trang chủ')
                ->columns(2)
                ->schema([
                    TextInput::make('site.hero_eyebrow')->label('Dòng chữ nhỏ trên tiêu đề')->maxLength(60),
                    TextInput::make('site.hero_cta')->label('Chữ trên nút chính')->maxLength(30),
                    TextInput::make('site.hero_title')
                        ->label('Tiêu đề lớn')
                        ->helperText('Đặt chữ trong dấu sao để tô màu nhấn: Dành một giờ cho *chính mình*.')
                        ->maxLength(80)
                        ->columnSpanFull(),
                    Textarea::make('site.hero_subtitle')->label('Mô tả dưới tiêu đề')->rows(2)->maxLength(200)->columnSpanFull(),
                    TextInput::make('site.cta_title')->label('Khối kêu gọi đặt lịch cuối trang')->maxLength(80),
                    TextInput::make('site.cta_button')->label('Chữ trên nút của khối đó')->maxLength(30),
                    Textarea::make('site.about')
                        ->label('Giới thiệu ngắn ở chân trang')
                        ->rows(2)
                        ->maxLength(300)
                        ->columnSpanFull(),
                ]),
            Section::make('Thanh thông báo')
                ->description('Dải chữ trên cùng mọi trang: khuyến mãi, lịch nghỉ lễ...')
                ->columns(3)
                ->schema([
                    Toggle::make('site.announcement_active')->label('Hiển thị')->inline(false)->live(),
                    TextInput::make('site.announcement')
                        ->label('Nội dung')
                        ->placeholder('Giảm 10% cho khách đặt online trong tháng 10!')
                        ->maxLength(140)
                        ->required(fn (Get $get) => (bool) $get('site.announcement_active'))
                        ->columnSpan(2),
                ]),
            Section::make('Đo lường')
                ->columns(2)
                ->collapsed()
                ->schema([
                    TextInput::make('site.ga_id')
                        ->label('Google Analytics 4 (Measurement ID)')
                        ->placeholder('G-XXXXXXXXXX')
                        ->regex('/^G-[A-Z0-9]+$/'),
                    TextInput::make('site.fb_pixel_id')
                        ->label('Facebook Pixel ID')
                        ->placeholder('1234567890')
                        ->regex('/^\d{6,20}$/'),
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
            Section::make('Bot Telegram')
                ->description('Bot báo lịch mới cho chủ tiệm, có nút Xác nhận / Từ chối ngay trong tin nhắn.')
                ->schema([
                    Text::make(fn () => $this->botStatusText()),
                    TextInput::make('bot.token')
                        ->label(fn () => app(TelegramConfig::class)->isConfigured() ? 'Đổi token bot' : 'Token bot')
                        ->password()
                        ->revealable()
                        ->autocomplete('off')
                        ->placeholder(fn () => app(TelegramConfig::class)->isConfigured()
                            ? 'Để trống nếu giữ nguyên bot hiện tại'
                            : 'Dán token từ @BotFather, dạng 7412345678:AAH...')
                        ->helperText('Tạo bot: mở Telegram, chat với @BotFather, gõ /newbot. Token được kiểm tra khi bấm Lưu và lưu mã hóa.')
                        ->regex('/^\d+:[A-Za-z0-9_-]{30,}$/')
                        ->validationMessages(['regex' => 'Token không đúng dạng (số:chuỗi ký tự).']),
                    Actions::make([
                        Action::make('checkBot')
                            ->label('Kiểm tra kết nối')
                            ->icon(Heroicon::OutlinedSignal)
                            ->color('gray')
                            ->visible(fn () => app(TelegramConfig::class)->isConfigured())
                            ->action(fn () => $this->checkBot()),
                        Action::make('enableWebhook')
                            ->label('Bật webhook')
                            ->icon(Heroicon::OutlinedBolt)
                            ->color('gray')
                            ->visible(fn () => app(TelegramConfig::class)->isConfigured() && app(TelegramSetup::class)->webhookUrl())
                            ->action(fn () => $this->runSetup(fn (TelegramSetup $setup) => 'Đã bật webhook: '.$setup->enableWebhook())),
                        Action::make('disableWebhook')
                            ->label('Tắt webhook')
                            ->color('gray')
                            ->visible(fn () => app(TelegramConfig::class)->isConfigured() && app(TelegramSetup::class)->webhookUrl())
                            ->requiresConfirmation()
                            ->action(fn () => $this->runSetup(function (TelegramSetup $setup) {
                                $setup->disableWebhook();

                                return 'Đã tắt webhook: bot ngừng nhận tin nhắn.';
                            })),
                        Action::make('forgetBot')
                            ->label('Xóa cấu hình bot')
                            ->color('danger')
                            ->visible(fn () => app(TelegramConfig::class)->source() === 'admin')
                            ->requiresConfirmation()
                            ->modalDescription('Bot sẽ ngừng gửi thông báo. Tài khoản admin đã liên kết vẫn được giữ, nhập lại token cùng bot là dùng tiếp.')
                            ->action(function () {
                                app(TelegramConfig::class)->forget();
                                Notification::make()->success()->title('Đã xóa cấu hình bot')->send();
                            }),
                    ])->key('botActions'),
                ]),
            Section::make('Thông báo')
                ->columns(2)
                ->schema([
                    TextInput::make('telegram.group_chat_id')
                        ->label('Chat ID của group')
                        ->helperText('Thêm bot vào group của tiệm, gõ /chatid rồi dán số vào đây. Để trống = gửi riêng cho từng admin đã liên kết.')
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

    private function botStatusText(): string
    {
        $config = app(TelegramConfig::class);

        if (! $config->isConfigured()) {
            return 'Chưa kết nối bot.';
        }

        $mode = app(TelegramSetup::class)->webhookUrl()
            ? 'Server có HTTPS: bot nhận tin qua webhook.'
            : 'Máy chưa có HTTPS công khai: chạy "make telegram" để bot nhận tin nhắn.';

        return 'Đang dùng bot @'.$config->username().' (token '.$config->tokenHint()
            .($config->source() === 'env' ? ', lấy từ file .env' : '').'). '.$mode;
    }

    private function checkBot(): void
    {
        try {
            $status = app(TelegramSetup::class)->status();
        } catch (TelegramException $e) {
            Notification::make()->danger()->title('Không kết nối được bot')->body($e->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title("Bot @{$status['bot']} hoạt động")
            ->body(implode("\n", array_filter([
                'Webhook: '.($status['webhook'] ?? 'chưa bật (dùng polling)'),
                $status['pending'] ? "{$status['pending']} tin nhắn đang chờ xử lý" : null,
                $status['last_error'] ? 'Lỗi gần nhất: '.$status['last_error'] : null,
            ])))
            ->send();
    }

    /** @param  Closure(TelegramSetup): string  $callback */
    private function runSetup(Closure $callback): void
    {
        try {
            Notification::make()->success()->title($callback(app(TelegramSetup::class)))->send();
        } catch (TelegramException $e) {
            Notification::make()->danger()->title('Telegram báo lỗi')->body($e->getMessage())->send();
        }
    }
}
