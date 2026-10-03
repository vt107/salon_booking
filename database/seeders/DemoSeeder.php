<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingStatusHistory;
use App\Models\ClosedDay;
use App\Models\Customer;
use App\Models\QrCode;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Booking\BookingCodeGenerator;
use App\Services\Booking\BookingSettings;
use App\Services\Voucher\VoucherException;
use App\Services\Voucher\VoucherQuote;
use App\Services\Voucher\VoucherService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dữ liệu demo (máy local và bản demo DEMO_MODE=true): một tiệm đang hoạt động.
 *
 * - 90 ngày lịch sử (khách tăng dần, cuối tuần đông, vài ngày cao điểm) + 14 ngày tới, đủ mọi trạng thái booking.
 * - Ngày giờ tính theo now(): demo:reset chạy lại mỗi ngày nên dữ liệu luôn mới.
 * - Booking dựng nối tiếp theo ca của từng thợ nên không trùng giờ (start_at → occupied_until), tránh lịch nghỉ
 *   và ngày tiệm nghỉ: khung giờ trống trên form đặt lịch khớp với dữ liệu.
 * - Lịch chờ duyệt chỉ từ ngày mai, hạn duyệt sau lần reset kế tiếp: bookings:expire-pending không hủy mất trong ngày.
 * - Voucher đi qua VoucherService (điều kiện, phân bổ giảm giá, đếm lượt) như khi khách đặt thật.
 */
class DemoSeeder extends Seeder
{
    private const PAST_DAYS = 90;

    private const FUTURE_DAYS = 14;

    /** Token giả: đủ để màn hình Telegram hiện trạng thái "đã kết nối", không gọi được Telegram */
    private const FAKE_BOT_TOKEN = '7000000000:DEMO-token-gia-khong-ket-noi-duoc-Telegram';

    /** Giá trước đợt tăng giá 45 ngày trước: booking cũ giữ giá lúc đặt (snapshot trong booking_items) */
    private const OLD_PRICES = ['cat-toc-nam' => 90_000, 'goi-duong-sinh' => 100_000, 'son-gel-tay' => 130_000];

    private const PRICE_CHANGE_DAYS_AGO = 45;

    private User $admin;

    private User $manager;

    /** @var array<int, User> staff_id => tài khoản của thợ */
    private array $staffUsers = [];

    /** @var Collection<int, Customer> */
    private Collection $customers;

    /** @var list<int> trọng số chọn khách: khách quen đặt nhiều lần */
    private array $customerWeights = [];

    /** @var array<string, Voucher> */
    private array $vouchers = [];

    /** @var Collection<int, QrCode> */
    private Collection $qrCodes;

    /** @var array<int, int> customer_id => số lịch đang chờ duyệt */
    private array $pendingCount = [];

    /** @var array<string, true> */
    private array $closedDates = [];

    /** @var array<string, true> */
    private array $peakDays = [];

    /** @var array<int, list<array{Carbon, Carbon}>> staff_id => lịch nghỉ */
    private array $timeOffs = [];

    /** Lịch chờ duyệt phải bắt đầu sau mốc này (hạn duyệt = giờ hẹn - 30' nằm sau lần reset kế tiếp) */
    private Carbon $pendingAfter;

    public function __construct(
        private BookingCodeGenerator $codes,
        private VoucherService $voucherService,
    ) {}

    public function run(): void
    {
        $this->admin = User::where('email', 'admin@salon.test')->firstOrFail();
        $this->pendingAfter = $this->nextReset()->addMinutes(BookingSettings::DEFAULTS['approval_min_before_start']);

        $this->seedSettings();
        $this->seedCatalog();
        $this->seedAccounts();
        $this->seedCustomers();
        $this->seedVouchersAndQrCodes();
        $this->seedDaysOff();

        foreach (Arr::random(range(3, self::PAST_DAYS - 1), 5) as $daysAgo) {
            $this->peakDays[today()->subDays($daysAgo)->toDateString()] = true;
        }

        $staffList = Staff::withTrashed()->with(['services', 'schedules'])->orderBy('sort_order')->get();

        DB::transaction(function () use ($staffList) {
            for ($day = today()->subDays(self::PAST_DAYS); $day->lte(today()->addDays(self::FUTURE_DAYS)); $day->addDay()) {
                if (isset($this->closedDates[$day->toDateString()])) {
                    continue;
                }

                foreach ($staffList as $staff) {
                    // Thợ đã nghỉ việc chỉ có lịch trước ngày nghỉ
                    if ($staff->trashed() && $day->gte($staff->deleted_at->copy()->startOfDay())) {
                        continue;
                    }

                    $this->seedStaffDay($staff, $day->copy());
                }
            }
        });

        // Khách chỉ được tạo khi đặt lịch: bỏ những người không được chọn lần nào
        Customer::doesntHave('bookings')->delete();
        $this->refreshCustomerStats();
        $this->blockRepeatNoShows();

        // Lượt quét nhiều hơn số lịch đặt được từ mã đó (không phải ai quét cũng đặt)
        foreach ($this->qrCodes as $qr) {
            $qr->forceFill(['scan_count' => $qr->bookings()->count() * fake()->numberBetween(2, 4) + fake()->numberBetween(3, 25)])->save();
        }

        Cache::forget('settings.all');
    }

    /** Thông tin tiệm, nội dung website và bot Telegram (token giả, lưu mã hóa như khi admin nhập) */
    private function seedSettings(): void
    {
        $values = [
            'shop.name' => 'Salon Mây',
            'shop.email' => 'xinchao@salon.test',
            'site.hero_eyebrow' => 'Tóc · Nail · Gội dưỡng sinh · Spa',
            'site.about' => 'Tiệm nhỏ, yên tĩnh ở trung tâm Quận 1. Đặt lịch online, đến đúng giờ là được phục vụ ngay, không phải chờ.',
            'site.announcement' => 'Ưu đãi tháng này: giảm 50.000đ cho hóa đơn từ 300.000đ, nhập mã GIAM50K khi đặt lịch.',
            'site.announcement_active' => true,
            // Bản demo không cần lên Google
            'site.noindex' => true,
            'telegram.bot_token' => Crypt::encryptString(self::FAKE_BOT_TOKEN),
            'telegram.bot_username' => 'salon_may_demo_bot',
        ];

        foreach ($values as $name => $value) {
            Setting::set($name, $value);
        }
    }

    /** Mô tả dịch vụ, giới thiệu thợ, một dịch vụ tạm ngưng và một thợ đã nghỉ việc (còn lịch sử, doanh thu) */
    private function seedCatalog(): void
    {
        $descriptions = [
            'cat-toc-nam' => 'Tư vấn kiểu theo khuôn mặt, cắt, gội và vuốt sáp',
            'cat-toc-nu' => 'Cắt tỉa tạo kiểu, giá theo độ dài tóc',
            'cao-mat-ray-tai' => 'Cạo mặt bằng dao, lấy ráy tai nhẹ nhàng',
            'goi-duong-sinh' => 'Gội thảo dược, massage cổ vai gáy 20 phút',
            'goi-say-tao-kieu' => 'Gội sạch, sấy phồng hoặc uốn cụp bằng lược',
            'nhuom-thoi-trang' => 'Thuốc nhuộm không amoniac, giá theo độ dài tóc',
            'uon-duoi' => 'Uốn lạnh, uốn setting hoặc duỗi phục hồi',
            'son-gel-tay' => 'Cắt da, dũa form, sơn gel bền màu 3 tuần',
            'dap-bot-up-mong' => 'Đắp bột hoặc úp móng giả, vẽ đơn giản',
            'cham-soc-mong-chan' => 'Ngâm chân thảo mộc, cắt da, sơn thường',
            'cham-soc-da-mat-co-ban' => 'Làm sạch sâu, tẩy tế bào chết, đắp mặt nạ',
            'massage-body-thu-gian' => 'Massage tinh dầu toàn thân 60 phút',
        ];
        foreach ($descriptions as $slug => $text) {
            Service::where('slug', $slug)->update(['short_description' => $text]);
        }

        Service::create([
            'category_id' => ServiceCategory::where('slug', 'goi-say')->value('id'),
            'name' => 'Hấp dầu phục hồi',
            'slug' => 'hap-dau-phuc-hoi',
            'short_description' => 'Tạm ngưng: đang đổi nhà cung cấp sản phẩm',
            'price' => 150_000,
            'duration_minutes' => 40,
            'buffer_minutes' => 5,
            'is_active' => false,
            'sort_order' => 9,
        ]);

        $bios = [
            'minh-tuan' => ['10 năm đứng kéo, từng làm ở salon lớn tại Sài Gòn. Mạnh về tóc nam undercut và nhuộm màu lạnh.', '0912000101'],
            'hoang-nam' => ['Barber trẻ, cắt fade sạch, cạo mặt bằng dao truyền thống. Làm cả ca tối cho khách đi làm về.', '0912000102'],
            'thu-trang' => ['Nail artist vẽ tay, chuyên móng cô dâu và móng đi tiệc.', '0912000103'],
            'ngoc-anh' => ['Kỹ thuật viên nail cẩn thận, nhẹ tay, khách quen đặt nhiều nhất ca tối.', '0912000104'],
            'lan-huong' => ['Chuyên viên spa có chứng chỉ chăm sóc da, gội dưỡng sinh bấm huyệt.', '0912000105'],
        ];
        foreach ($bios as $slug => [$bio, $phone]) {
            Staff::where('slug', $slug)->update(['bio' => $bio, 'phone' => $phone]);
        }

        $former = Staff::create([
            'name' => 'Quốc Bảo',
            'slug' => 'quoc-bao',
            'title' => 'Barber',
            'bio' => 'Đã nghỉ việc, giữ lại để xem lịch sử và doanh thu.',
            'is_bookable' => false,
            'is_active' => false,
            'sort_order' => 9,
        ]);
        $former->services()->sync(Service::whereHas('category', fn ($q) => $q->whereIn('slug', ['cat-toc', 'goi-say']))->where('is_active', true)->pluck('id'));
        foreach ([0, 2, 3, 4, 5, 6] as $day) {
            $former->schedules()->create(['day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '18:00']);
        }
        $former->forceFill(['deleted_at' => today()->subDays(75)->setTime(18, 0)])->save();
    }

    /** Tài khoản thử phân quyền: quản lý duyệt lịch, nhân viên gắn với thợ; admin / quản lý đã liên kết Telegram */
    private function seedAccounts(): void
    {
        $this->admin->forceFill(['phone' => '0901234567', 'telegram_user_id' => 700000001, 'telegram_username' => 'chutiem_salonmay'])->save();

        $this->manager = User::firstOrCreate(['email' => 'quanly@salon.test'], [
            'name' => 'Chị Lan (quản lý)',
            'password' => 'password',
            'role' => UserRole::Manager,
        ]);
        $this->manager->forceFill(['phone' => '0901234568', 'telegram_user_id' => 700000002, 'telegram_username' => 'lan_quanly', 'notify_telegram' => false])->save();

        $accounts = [
            ['nhanvien@salon.test', 'Hoàng Nam', 'hoang-nam', true],
            ['thutrang@salon.test', 'Thu Trang', 'thu-trang', true],
            ['quocbao@salon.test', 'Quốc Bảo', 'quoc-bao', false],
        ];

        foreach ($accounts as [$email, $name, $slug, $active]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'role' => UserRole::Staff,
            ]);
            $user->forceFill(['is_active' => $active])->save();

            $staff = Staff::withTrashed()->where('slug', $slug)->first();
            $staff?->forceFill(['user_id' => $user->id])->save();
            if ($staff) {
                $this->staffUsers[$staff->id] = $user;
            }
        }
    }

    private function seedCustomers(): void
    {
        // Factory gắn lại $this của closure state: lấy sẵn callable
        $emailFor = $this->emailFor(...);
        $this->customers = Customer::factory(450)
            ->state(fn (array $attributes) => ['email' => $attributes['email'] ? $emailFor($attributes['name']) : null])
            ->create();

        $notes = [
            'Thích cắt ngắn gọn, không vuốt sáp',
            'Dị ứng thuốc nhuộm có PPD: dùng dòng hữu cơ',
            'Da nhạy cảm, tránh sản phẩm có cồn',
            'Khách quen của anh Tuấn',
            'Hay đi cùng con gái nhỏ',
            'Thích gội nước ấm, bấm huyệt mạnh tay',
            'Móng yếu, không đắp bột',
            'Thường đặt ca tối sau giờ làm',
        ];
        foreach ($this->customers->random(count($notes)) as $i => $customer) {
            $customer->forceFill(['note' => $notes[$i]])->save();
        }

        // ~15% khách quen (gần như tuần nào cũng đến), ~35% vài tuần một lần, còn lại thỉnh thoảng
        $this->customerWeights = $this->customers->map(fn () => fake()->randomElement([...array_fill(0, 3, 6), ...array_fill(0, 7, 2), ...array_fill(0, 10, 1)]))->all();
    }

    private function seedVouchersAndQrCodes(): void
    {
        $nailServiceIds = Service::whereHas('category', fn ($q) => $q->where('slug', 'nail'))->pluck('id');

        $definitions = [
            'WELCOME10' => ['name' => 'Giảm 10% cho khách mới', 'description' => 'Áp dụng cho lần đặt lịch đầu tiên, giảm tối đa 50.000đ.', 'type' => VoucherType::Percent, 'value' => 10, 'max_discount' => 50_000, 'first_booking_only' => true],
            'GIAM50K' => ['name' => 'Giảm 50.000đ cho hóa đơn từ 300.000đ', 'description' => 'Ưu đãi tháng này, mỗi khách dùng tối đa 2 lần.', 'type' => VoucherType::Fixed, 'value' => 50_000, 'min_order_amount' => 300_000, 'usage_limit' => 200, 'usage_limit_per_customer' => 2, 'starts_at' => today()->subDays(30), 'ends_at' => today()->addDays(30)->endOfDay()],
            'NAIL20' => ['name' => 'Giảm 20% dịch vụ Nail', 'description' => 'Chỉ giảm trên các dịch vụ nail trong lịch hẹn.', 'type' => VoucherType::Percent, 'value' => 20, 'max_discount' => 100_000, 'scope' => VoucherScope::Services],
            'KHAITRUONG' => ['name' => 'Mừng khai trương phòng spa: giảm 100.000đ', 'description' => 'Hóa đơn từ 500.000đ, giới hạn 15 lượt.', 'type' => VoucherType::Fixed, 'value' => 100_000, 'min_order_amount' => 500_000, 'usage_limit' => 15, 'usage_limit_per_customer' => 1, 'starts_at' => today()->subDays(75), 'ends_at' => today()->addDays(15)->endOfDay()],
            'HE15' => ['name' => 'Ưu đãi hè: giảm 15%', 'description' => 'Đã hết hạn.', 'type' => VoucherType::Percent, 'value' => 15, 'max_discount' => 80_000, 'starts_at' => today()->subDays(120), 'ends_at' => today()->subDays(55)->endOfDay()],
            'CUOINAM' => ['name' => 'Ưu đãi cuối năm: giảm 15%', 'description' => 'Sắp diễn ra.', 'type' => VoucherType::Percent, 'value' => 15, 'max_discount' => 120_000, 'starts_at' => today()->addDays(20), 'ends_at' => today()->addDays(60)->endOfDay()],
            'SINHNHAT' => ['name' => 'Giảm 20% trong tháng sinh nhật', 'description' => 'Tạm ngưng, sẽ mở lại khi có quà tặng kèm.', 'type' => VoucherType::Percent, 'value' => 20, 'max_discount' => 100_000, 'is_active' => false],
            'THU10' => ['name' => 'Giảm 10% mùa thu', 'description' => 'Đã thay bằng GIAM50K.', 'type' => VoucherType::Percent, 'value' => 10, 'max_discount' => 50_000],
        ];

        foreach ($definitions as $code => $attributes) {
            $this->vouchers[$code] = Voucher::create(['code' => $code, 'scope' => VoucherScope::All, ...$attributes]);
        }
        $this->vouchers['NAIL20']->services()->sync($nailServiceIds);
        $this->vouchers['THU10']->delete();

        $tuan = Staff::where('slug', 'minh-tuan')->value('id');
        $gel = Service::where('slug', 'son-gel-tay')->value('id');

        $this->qrCodes = collect([
            QrCode::create(['code' => 'QUAY01', 'name' => 'Poster quầy lễ tân']),
            QrCode::create(['code' => 'FANPAGE', 'name' => 'Fanpage Facebook', 'voucher_id' => $this->vouchers['WELCOME10']->id]),
            QrCode::create(['code' => 'TUAN01', 'name' => 'Danh thiếp anh Minh Tuấn', 'staff_id' => $tuan]),
            QrCode::create(['code' => 'NAILGEL', 'name' => 'Tờ rơi Nail', 'service_id' => $gel, 'voucher_id' => $this->vouchers['NAIL20']->id]),
            QrCode::create(['code' => 'TOROI', 'name' => 'Tờ rơi khai trương (đã ngừng phát)', 'is_active' => false]),
        ]);
        // refresh(): lấy cả giá trị mặc định của cột (is_active) để lọc mã đang dùng
        $this->qrCodes->each(fn (QrCode $qr, int $i) => $qr->forceFill(['created_at' => today()->subDays(100 - $i * 12)])->save() && $qr->refresh());
    }

    /** Ngày tiệm nghỉ và lịch nghỉ của thợ (thấy trên lịch theo ngày, khung giờ của form đặt lịch tự trừ ra) */
    private function seedDaysOff(): void
    {
        foreach ([[-26, 'Nghỉ sửa điện nước'], [10, 'Cả tiệm đi học lớp kỹ thuật nhuộm']] as [$days, $reason]) {
            $date = today()->addDays($days);
            ClosedDay::create(['date' => $date, 'reason' => $reason]);
            $this->closedDates[$date->toDateString()] = true;
        }

        $offs = [
            ['minh-tuan', today()->setTime(14, 0), today()->setTime(18, 0), 'Nghỉ chiều, việc gia đình'],
            ['hoang-nam', today()->addDay()->setTime(8, 30), today()->addDay()->setTime(12, 0), 'Khám sức khỏe định kỳ'],
            ['ngoc-anh', today()->addDays(3), today()->addDays(4)->endOfDay(), 'Nghỉ phép'],
            ['lan-huong', today()->subDays(20), today()->subDays(20)->endOfDay(), 'Nghỉ ốm'],
        ];

        foreach ($offs as [$slug, $from, $to, $reason]) {
            $staff = Staff::where('slug', $slug)->first();
            StaffTimeOff::create(['staff_id' => $staff->id, 'start_at' => $from, 'end_at' => $to, 'reason' => $reason, 'created_by' => $this->admin->id]);
            $this->timeOffs[$staff->id][] = [$from, $to];
        }
    }

    private function seedStaffDay(Staff $staff, Carbon $day): void
    {
        $offset = (int) today()->diffInDays($day, false);
        $fill = $this->fillRate($day, $offset);

        foreach ($staff->schedules->where('day_of_week', $day->dayOfWeek)->sortBy('start_time') as $shift) {
            $cursor = $day->copy()->setTimeFromTimeString($shift->start_time);
            $shiftEnd = $day->copy()->setTimeFromTimeString($shift->end_time);

            while ($cursor->lt($shiftEnd)) {
                $cursor->addMinutes(fake()->randomElement([0, 0, 0, 15, 15, 30]));

                // Chừa khoảng trống: ngày vắng / ngày càng xa trong tương lai thì càng thưa
                if (! fake()->boolean($fill)) {
                    $cursor->addMinutes(30);

                    continue;
                }

                $services = $this->pickServices($staff);
                $minutes = $services->sum(fn (Service $s) => $s->pivot->custom_duration_minutes ?? $s->duration_minutes);
                $occupiedUntil = $cursor->copy()->addMinutes($minutes + $services->last()->buffer_minutes);

                if ($occupiedUntil->gt($shiftEnd) || $this->overlapsTimeOff($staff->id, $cursor, $occupiedUntil)) {
                    $cursor->addMinutes(15);

                    continue;
                }

                $this->createBooking($staff, $services, $cursor->copy(), $offset);

                // Lịch kế tiếp bắt đầu theo lưới 15 phút như form đặt lịch
                $cursor = $occupiedUntil->copy()->addMinutes((15 - $occupiedUntil->minute % 15) % 15);
            }
        }
    }

    /** % khả năng một khung 30 phút có khách */
    private function fillRate(Carbon $day, int $offset): int
    {
        if ($offset > 0) {
            return max(12, 62 - $offset * 5);
        }

        // Khách tăng dần trong 90 ngày (~20% → 75%), cuối tuần đông hơn
        $rate = 75 + (int) round($offset * 55 / self::PAST_DAYS);
        $rate += match ($day->dayOfWeek) {
            0, 6 => 14,
            5 => 6,
            default => 0,
        };

        return min(92, $rate + (isset($this->peakDays[$day->toDateString()]) ? 22 : 0));
    }

    /** 1–3 dịch vụ thợ làm được, dịch vụ dài (nhuộm, uốn) ít được chọn hơn; theo thứ tự trong bảng giá */
    private function pickServices(Staff $staff): Collection
    {
        $count = min($staff->services->count(), fake()->randomElement([1, 1, 1, 1, 1, 1, 2, 2, 2, 3]));
        $pool = $staff->services->flatMap(fn (Service $s) => array_fill(0, $s->duration_minutes >= 90 ? 1 : 4, $s));
        $picked = collect();

        while ($picked->count() < $count) {
            $service = $pool->random();
            $picked->put($service->id, $service);
        }

        return $picked->sortBy(['category_id', 'sort_order'])->values();
    }

    private function overlapsTimeOff(int $staffId, Carbon $from, Carbon $to): bool
    {
        foreach ($this->timeOffs[$staffId] ?? [] as [$start, $end]) {
            if ($from->lt($end) && $to->gt($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    private function createBooking(Staff $staff, Collection $services, Carbon $startAt, int $offset): void
    {
        $customer = $this->pickCustomer();
        $items = $this->items($services, $startAt, $offset);
        $endAt = end($items)['end_at']->copy();
        $source = $this->pickSource($startAt);
        $byStaff = in_array($source, [BookingSource::Admin, BookingSource::Phone, BookingSource::WalkIn], true);
        [$status, $cancelledBy] = $this->pickStatus($startAt, $endAt, $source, $customer->id);

        $createdAt = match (true) {
            $source === BookingSource::WalkIn => $startAt->copy()->subMinutes(fake()->numberBetween(2, 20)),
            $status === BookingStatus::Pending => now()->subMinutes(fake()->numberBetween(10, 600)),
            default => $startAt->copy()->subMinutes(fake()->numberBetween(90, 6 * 24 * 60)),
        };
        // Lịch xa trong tương lai: đặt trong vài ngày gần đây
        if ($createdAt->gte(now())) {
            $createdAt = now()->subMinutes(fake()->numberBetween(5, 4 * 24 * 60));
        }

        $qr = $source === BookingSource::Qr ? $this->pickQr($staff, $services, $startAt) : null;
        if ($source === BookingSource::Qr && ! $qr) {
            $source = BookingSource::Web;
        }

        $quote = $this->quoteVoucher($customer, $items, $createdAt, $qr);
        foreach ($quote?->allocations ?? [] as $index => $amount) {
            $items[$index]['discount_amount'] = $amount;
        }

        $subtotal = array_sum(array_column($items, 'price'));
        $discount = $quote?->discount ?? 0;
        $creator = $byStaff ? $this->staffActor($staff) : null;
        $approver = $creator ?? fake()->randomElement([$this->admin, $this->admin, $this->manager]);
        $times = $this->statusTimestamps($status, $cancelledBy, $startAt, $endAt, $createdAt, $byStaff);
        $updatedAt = collect($times)->except('approval_deadline_at')->filter(fn ($value) => $value instanceof Carbon)->push($createdAt)->max();

        $booking = new Booking;
        $booking->forceFill([
            'code' => $this->codes->generate(),
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'occupied_until' => $endAt->copy()->addMinutes($services->last()->buffer_minutes),
            'status' => $status,
            'source' => $source,
            'qr_code_id' => $qr?->id,
            'is_staff_auto_assigned' => ! $byStaff && fake()->boolean(35),
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => $subtotal - $discount,
            'voucher_id' => $quote?->voucher->id,
            'customer_note' => fake()->boolean(12) ? fake()->randomElement([
                'Da nhạy cảm', 'Muốn tóc ngắn gọn, mái dài', 'Đi cùng bạn, xếp hai người liền nhau giúp em',
                'Có thể đến trễ 5 phút', 'Cho em thợ nữ ạ', 'Móng tròn, màu nude',
            ]) : null,
            'internal_note' => fake()->boolean(6) ? fake()->randomElement([
                'Khách quen, ưu tiên phòng riêng', 'Đã gọi xác nhận lại', 'Khách muốn làm nhanh, có hẹn sau đó', 'Lần trước phàn nàn nước gội hơi nóng',
            ]) : null,
            'created_by' => $creator?->id,
            'confirmed_by' => isset($times['confirmed_at']) ? $approver->id : null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            ...$times,
        ])->save();

        BookingItem::insert(array_map(fn (array $item) => [
            ...$item,
            'booking_id' => $booking->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $items));

        if ($quote) {
            $this->redeemVoucher($quote, $booking, $createdAt);
        }

        $this->writeStatusHistory($booking, $staff, $creator, $approver, $createdAt);

        if ($status === BookingStatus::Pending) {
            $this->pendingCount[$customer->id] = ($this->pendingCount[$customer->id] ?? 0) + 1;
        }
    }

    /**
     * Dòng dịch vụ nối tiếp nhau, giá / thời lượng theo thợ (snapshot lúc đặt).
     *
     * @param  Collection<int, Service>  $services
     * @return list<array<string, mixed>>
     */
    private function items(Collection $services, Carbon $startAt, int $offset): array
    {
        $items = [];
        $cursor = $startAt->copy();

        foreach ($services as $i => $service) {
            $minutes = $service->pivot->custom_duration_minutes ?? $service->duration_minutes;
            $price = $service->pivot->custom_price
                ?? ($offset < -self::PRICE_CHANGE_DAYS_AGO ? (self::OLD_PRICES[$service->slug] ?? null) : null)
                ?? $service->price;

            $items[] = [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'price' => $price,
                'discount_amount' => 0,
                'duration_minutes' => $minutes,
                'start_at' => $cursor->copy(),
                'end_at' => $cursor->addMinutes($minutes)->copy(),
                'sort_order' => $i,
            ];
        }

        return $items;
    }

    private function pickCustomer(): Customer
    {
        $target = fake()->numberBetween(1, array_sum($this->customerWeights));

        foreach ($this->customerWeights as $index => $weight) {
            $target -= $weight;
            if ($target <= 0) {
                return $this->customers[$index];
            }
        }

        return $this->customers->last();
    }

    private function pickSource(Carbon $startAt): BookingSource
    {
        $source = fake()->randomElement([
            ...array_fill(0, 46, BookingSource::Web),
            ...array_fill(0, 14, BookingSource::Qr),
            ...array_fill(0, 18, BookingSource::Phone),
            ...array_fill(0, 14, BookingSource::WalkIn),
            ...array_fill(0, 8, BookingSource::Admin),
        ]);

        // Khách vãng lai chỉ có ở các lịch đã tới giờ
        return $source === BookingSource::WalkIn && $startAt->isFuture() ? BookingSource::Phone : $source;
    }

    /**
     * Trạng thái hợp với thời điểm: đã qua thì hoàn thành / hủy / không đến / từ chối, đang diễn ra thì đang làm,
     * sắp tới thì đã xác nhận hoặc chờ duyệt. Lịch nhân viên tạo hộ được xác nhận luôn (như BookingService).
     *
     * @return array{BookingStatus, CancelledBy|null}
     */
    private function pickStatus(Carbon $startAt, Carbon $endAt, BookingSource $source, int $customerId): array
    {
        $byStaff = in_array($source, [BookingSource::Admin, BookingSource::Phone, BookingSource::WalkIn], true);

        if ($endAt->lte(now())) {
            $pick = $source === BookingSource::WalkIn ? 100 : fake()->numberBetween(1, 100);

            return match (true) {
                $pick <= 5 => [BookingStatus::Cancelled, CancelledBy::Customer],
                $pick <= 7 => [BookingStatus::Cancelled, CancelledBy::Staff],
                $pick <= 12 => [BookingStatus::NoShow, null],
                ! $byStaff && $pick <= 15 => [BookingStatus::Rejected, null],
                ! $byStaff && $pick <= 16 => [BookingStatus::Cancelled, CancelledBy::System],
                default => [BookingStatus::Completed, null],
            };
        }

        if ($startAt->lte(now())) {
            return [BookingStatus::InProgress, null];
        }

        $canBePending = ! $byStaff
            && $startAt->gt($this->pendingAfter)
            && $startAt->lt(today()->addDays(8))
            && ($this->pendingCount[$customerId] ?? 0) < BookingSettings::DEFAULTS['max_pending_per_phone'];

        $pick = fake()->numberBetween(1, 100);

        return match (true) {
            $canBePending && $pick <= 32 => [BookingStatus::Pending, null],
            $pick >= 95 => [BookingStatus::Cancelled, CancelledBy::Customer],
            ! $byStaff && $pick >= 93 && $startAt->gt(today()->endOfDay()) => [BookingStatus::Rejected, null],
            default => [BookingStatus::Confirmed, null],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function statusTimestamps(BookingStatus $status, ?CancelledBy $cancelledBy, Carbon $startAt, Carbon $endAt, Carbon $createdAt, bool $byStaff): array
    {
        $approvedAt = $byStaff ? $createdAt->copy() : $createdAt->copy()->addMinutes(fake()->numberBetween(3, 90))->min($startAt)->min(now())->copy();
        $confirmed = ['confirmed_at' => $approvedAt];
        $reminder = $createdAt->lt($startAt->copy()->subDay()) && $startAt->copy()->subDay()->lte(now())
            ? ['reminder_sent_at' => $startAt->copy()->subDay()]
            : [];

        return match (true) {
            $status === BookingStatus::Pending => [
                'approval_deadline_at' => $startAt->copy()->subMinutes(BookingSettings::DEFAULTS['approval_min_before_start']),
            ],
            $status === BookingStatus::Confirmed => $confirmed + $reminder,
            $status === BookingStatus::Rejected => [
                'rejected_at' => $approvedAt,
                'status_reason' => fake()->randomElement(['Hết chỗ vào khung giờ này', 'Thợ bận đột xuất', 'Tiệm nghỉ vào ngày này']),
            ],
            $cancelledBy === CancelledBy::System => [
                'cancelled_at' => $createdAt->copy()->addMinutes(60)->min($startAt)->copy(),
                'cancelled_by' => CancelledBy::System,
                'status_reason' => 'Quá hạn xác nhận',
            ],
            $status === BookingStatus::Cancelled => $confirmed + [
                'cancelled_at' => $startAt->copy()->subHours(fake()->numberBetween(3, 30))->max($approvedAt)->min(now())->copy(),
                'cancelled_by' => $cancelledBy,
                'status_reason' => $cancelledBy === CancelledBy::Staff
                    ? fake()->randomElement(['Thợ nghỉ ốm, đã gọi báo khách', 'Mất điện cả khu, đã hẹn lại khách'])
                    : fake()->randomElement(['Khách bận đột xuất', 'Khách đổi ý, hẹn dịp khác', 'Trùng lịch công tác']),
            ],
            $status === BookingStatus::NoShow => $confirmed + $reminder,
            $status === BookingStatus::InProgress => $confirmed + $reminder + ['checked_in_at' => $startAt->copy()],
            default => $confirmed + $reminder + [
                'checked_in_at' => $startAt->copy(),
                'completed_at' => $paidAt = $endAt->copy()->addMinutes(fake()->numberBetween(0, 8))->min(now())->copy(),
                'paid_at' => $paidAt,
                'payment_method' => fake()->randomElement([
                    ...array_fill(0, 7, PaymentMethod::Cash),
                    ...array_fill(0, 7, PaymentMethod::BankTransfer),
                    ...array_fill(0, 3, PaymentMethod::Card),
                    ...array_fill(0, 3, PaymentMethod::Ewallet),
                ]),
            ],
        };
    }

    /** Mã QR khách đã quét: mã của thợ / dịch vụ chỉ dẫn tới lịch của thợ / dịch vụ đó */
    private function pickQr(Staff $staff, Collection $services, Carbon $startAt): ?QrCode
    {
        $eligible = $this->qrCodes->filter(fn (QrCode $qr) => ($qr->is_active || $startAt->lt(today()->subDays(30)))
            && $qr->created_at->lte($startAt)
            && (! $qr->staff_id || $qr->staff_id === $staff->id)
            && (! $qr->service_id || $services->contains('id', $qr->service_id)));

        return $eligible->isEmpty() ? null : $eligible->random();
    }

    /**
     * Voucher khách nhập (hoặc đi kèm mã QR), kiểm tra bằng VoucherService tại thời điểm đặt.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function quoteVoucher(Customer $customer, array $items, Carbon $at, ?QrCode $qr): ?VoucherQuote
    {
        $qrVoucher = $qr?->voucher;

        if (! $qrVoucher && ! fake()->boolean(22)) {
            return null;
        }

        $codes = array_filter([
            $qrVoucher?->code,
            ! $customer->bookings()->exists() ? 'WELCOME10' : null,
            fake()->boolean() ? 'NAIL20' : null,
            fake()->randomElement(['KHAITRUONG', 'GIAM50K', 'GIAM50K', 'HE15']),
        ]);

        foreach ($codes as $code) {
            try {
                return $this->voucherService->quote($code, $items, $customer, $at);
            } catch (VoucherException) {
                // Không đủ điều kiện: thử mã khác
            }
        }

        return null;
    }

    private function redeemVoucher(VoucherQuote $quote, Booking $booking, Carbon $createdAt): void
    {
        $usage = $this->voucherService->redeem($quote, $booking);
        $usage->forceFill(['created_at' => $createdAt])->save();

        $releasedAt = $booking->cancelled_at ?? $booking->rejected_at;
        if ($releasedAt) {
            $this->voucherService->release($booking);
            $usage->forceFill(['released_at' => $releasedAt])->save();
        }
    }

    /** Người tạo lịch hộ khách: thợ có tài khoản thì tự nhập, không thì lễ tân (admin / quản lý) */
    private function staffActor(Staff $staff): User
    {
        $own = $this->staffUsers[$staff->id] ?? null;

        return $own && fake()->boolean(60) ? $own : fake()->randomElement([$this->admin, $this->manager]);
    }

    /** Lịch sử trạng thái giống luồng của BookingService (kể cả vài lần đổi giờ) */
    private function writeStatusHistory(Booking $booking, Staff $staff, ?User $creator, User $approver, Carbon $createdAt): void
    {
        $worker = $this->staffUsers[$staff->id] ?? $approver;
        $customer = $this->customers->firstWhere('id', $booking->customer_id);
        $rows = [];
        $add = function (?BookingStatus $from, BookingStatus $to, ?object $actor, ?Carbon $at, ?string $note = null) use (&$rows, $booking) {
            $rows[] = [
                'booking_id' => $booking->id,
                'from_status' => $from?->value,
                'to_status' => $to->value,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'note' => $note,
                'created_at' => $at,
            ];
        };

        $status = $booking->status;
        $add(null, $creator ? BookingStatus::Confirmed : BookingStatus::Pending, $creator ?? $customer, $createdAt);

        if ($status === BookingStatus::Pending) {
            BookingStatusHistory::insert($rows);

            return;
        }

        if ($status === BookingStatus::Rejected) {
            $add(BookingStatus::Pending, BookingStatus::Rejected, $approver, $booking->rejected_at, $booking->status_reason);
            BookingStatusHistory::insert($rows);

            return;
        }

        if ($booking->cancelled_by === CancelledBy::System) {
            $add(BookingStatus::Pending, BookingStatus::Cancelled, null, $booking->cancelled_at, $booking->status_reason);
            BookingStatusHistory::insert($rows);

            return;
        }

        if (! $creator) {
            $add(BookingStatus::Pending, BookingStatus::Confirmed, $approver, $booking->confirmed_at);
        }

        // Thỉnh thoảng khách nhờ đổi giờ trước khi đến
        $rescheduledAt = $booking->confirmed_at->copy()->addMinutes(fake()->numberBetween(30, 600));
        if (fake()->boolean(4) && $rescheduledAt->lt($booking->start_at) && $rescheduledAt->lt(now())) {
            $previous = $booking->start_at->copy()->subMinutes(fake()->randomElement([-90, -60, 60, 120]));
            $add(BookingStatus::Confirmed, BookingStatus::Confirmed, $approver, $rescheduledAt, "Đổi lịch từ {$previous->format('H:i d/m/Y')} (thợ #{$staff->id})");
        }

        if ($status === BookingStatus::Cancelled) {
            $add(BookingStatus::Confirmed, BookingStatus::Cancelled, $booking->cancelled_by === CancelledBy::Customer ? $customer : $approver, $booking->cancelled_at, $booking->status_reason);
        } elseif ($status === BookingStatus::NoShow) {
            // Scheduler bookings:mark-no-show đánh dấu, không có người thao tác
            $add(BookingStatus::Confirmed, BookingStatus::NoShow, null, $booking->start_at->copy()->addMinutes(BookingSettings::DEFAULTS['no_show_grace_minutes']));
        } elseif (in_array($status, [BookingStatus::InProgress, BookingStatus::Completed], true)) {
            $add(BookingStatus::Confirmed, BookingStatus::InProgress, $worker, $booking->checked_in_at);
            if ($status === BookingStatus::Completed) {
                $add(BookingStatus::InProgress, BookingStatus::Completed, $worker, $booking->completed_at);
            }
        }

        BookingStatusHistory::insert($rows);
    }

    private function refreshCustomerStats(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customers c
            LEFT JOIN (
                SELECT customer_id,
                       SUM(status = 'completed') AS visits,
                       SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) AS spent,
                       SUM(status = 'no_show') AS no_shows,
                       MAX(CASE WHEN status = 'completed' THEN start_at END) AS last_visit,
                       MIN(created_at) AS first_booked
                FROM bookings
                GROUP BY customer_id
            ) s ON s.customer_id = c.id
            SET c.total_visits = COALESCE(s.visits, 0),
                c.total_spent = COALESCE(s.spent, 0),
                c.no_show_count = COALESCE(s.no_shows, 0),
                c.last_visit_at = s.last_visit,
                c.created_at = COALESCE(s.first_booked, c.created_at),
                c.updated_at = COALESCE(s.first_booked, c.updated_at)
        SQL);
    }

    /** Khách hay bỏ hẹn bị chặn đặt online (không chặn khách đang có lịch chờ duyệt) */
    private function blockRepeatNoShows(): void
    {
        Customer::where('no_show_count', '>=', 2)
            ->whereDoesntHave('bookings', fn ($q) => $q->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed]))
            ->orderByDesc('no_show_count')
            ->take(2)
            ->get()
            ->each(fn (Customer $customer) => $customer->forceFill([
                'is_blocked' => true,
                'note' => "Không đến {$customer->no_show_count} lần: chỉ nhận lịch qua điện thoại",
            ])->save());
    }

    /** "Nguyễn Thị Thu Trang" => "thutrang.nguyen87@example.com" (tên miền dành riêng cho ví dụ) */
    private function emailFor(string $name): string
    {
        $parts = explode(' ', Str::lower(Str::ascii($name)));
        $given = implode('', array_slice($parts, -2));

        return $given.'.'.$parts[0].fake()->numberBetween(70, 99).'@example.com';
    }

    /** Lần demo:reset kế tiếp (DEMO_RESET_AT, mặc định 04:00) */
    private function nextReset(): Carbon
    {
        $reset = today()->setTimeFromTimeString((string) (config('demo.reset_at') ?: '04:00'));

        return $reset->lte(now()) ? $reset->addDay() : $reset;
    }
}
