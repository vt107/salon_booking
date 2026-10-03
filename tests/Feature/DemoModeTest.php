<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Livewire\BookingWizard;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\QrCode;
use App\Models\User;
use App\Providers\DemoServiceProvider;
use App\Services\Booking\BookingService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoModeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Chế độ demo chỉ xem (config/demo.php). PHPUnit chạy CLI nên phải forceGuard() để guard SQL hoạt động.
 */
class DemoModeTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $admin;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        RateLimiter::clear('booking-submit:127.0.0.1');

        $this->admin = User::factory()->admin()->create(['email' => 'admin@salon.test']);
        User::factory()->create(['email' => 'quanly@salon.test', 'role' => UserRole::Manager]);
        $this->booking = app(BookingService::class)->confirm(
            app(BookingService::class)->create($this->bookingData('2026-10-06 10:00', staff: $this->tuan)),
            $this->admin,
        );

        // DemoServiceProvider chỉ đăng ký guard / middleware / route khi boot với DEMO_MODE=true
        config(['demo.enabled' => true]);
        $this->app->register(DemoServiceProvider::class, force: true);
        $this->app['router']->getRoutes()->refreshNameLookups();
        DemoMode::forceGuard();
    }

    protected function tearDown(): void
    {
        DemoMode::forceGuard(false);
        parent::tearDown();
    }

    public function test_sql_writes_are_blocked_but_reads_and_infrastructure_tables_are_not(): void
    {
        $this->assertSame(1, Booking::count());
        DB::table('cache')->insert(['key' => 'demo-test', 'value' => 'x', 'expiration' => time() + 60]);

        $this->expectException(DemoModeException::class);
        Customer::create(['name' => 'Khách mới', 'phone' => '0912000999']);
    }

    public function test_bypass_allows_a_narrow_write(): void
    {
        DemoMode::bypass(fn () => $this->booking->update(['internal_note' => 'ghi chú']));

        $this->assertSame('ghi chú', $this->booking->fresh()->internal_note);
    }

    public function test_customer_cancel_form_is_blocked_with_a_flash_message(): void
    {
        $this->from($this->booking->manageUrl())
            ->post(URL::signedRoute('booking.cancel', ['booking' => $this->booking->code]))
            ->assertRedirect($this->booking->manageUrl())
            ->assertSessionHas('demo_blocked');

        $this->assertSame(BookingStatus::Confirmed, $this->booking->fresh()->status);

        // Trang quay lại hiện toast (widget là middleware toàn cục, flash được DemoReadOnly giữ lại)
        $this->get($this->booking->manageUrl())->assertSee('data-flash="'.DemoMode::message().'"', false);
    }

    public function test_telegram_webhook_gets_403_json(): void
    {
        $this->postJson(route('telegram.webhook'), ['update_id' => 1])->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_telegram_is_never_called_even_from_the_scheduler(): void
    {
        DemoMode::forceGuard(false);
        Http::fake();

        try {
            (new TelegramClient('7000000000:DEMO-token-gia'))->getMe();
            $this->fail('Bản demo không được gọi Telegram');
        } catch (TelegramException) {
            Http::assertNothingSent();
        }
    }

    public function test_booking_wizard_validates_then_stops_before_booking(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('toggleService', $this->haircut->id)
            ->call('next')
            ->call('selectDate', '2026-10-05')
            ->call('selectTime', '10:00')
            ->call('next')
            ->call('next')
            ->set('name', 'Lê Thu Hà')
            ->set('phone', '0977 123 456')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('demo-blocked')
            ->assertNoRedirect();

        $this->assertSame(1, Booking::count());
    }

    public function test_filament_save_is_blocked_with_a_toast(): void
    {
        $this->actingAs($this->admin);
        $customer = $this->booking->customer;

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['name' => 'Tên đã sửa'])
            ->call('save')
            ->assertDispatched('demo-blocked');

        $this->assertSame($customer->name, $customer->fresh()->name);
    }

    public function test_widget_is_injected_on_site_and_admin_pages(): void
    {
        $this->get('/')->assertOk()->assertSee('id="dmw"', false)->assertSee('admin@salon.test');

        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('id="dmw"', false);
    }

    public function test_login_is_prefilled_with_the_chosen_demo_account(): void
    {
        Livewire::test(Login::class)->assertSet('data.email', 'admin@salon.test')->assertSet('data.password', 'password');

        Livewire::withQueryParams(['demo' => 'manager'])->test(Login::class)->assertSet('data.email', 'quanly@salon.test');
    }

    public function test_switch_logs_out_and_opens_the_portal_login(): void
    {
        $this->actingAs($this->admin)
            ->get(route('demo.switch', 'manager'))
            ->assertRedirect(url('/admin/login').'?demo=manager');

        $this->assertGuest();
        $this->get(route('demo.switch', 'khong-co'))->assertNotFound();
    }

    public function test_qr_link_opens_the_form_without_counting_the_scan(): void
    {
        $qr = DemoMode::bypass(fn () => QrCode::create(['code' => 'QUAY01', 'name' => 'Quầy']));

        $this->get(route('qr.redirect', 'QUAY01'))->assertRedirect(route('booking.create'));
        $this->assertSame(0, $qr->fresh()->scan_count);
    }

    public function test_tick_runs_todays_bookings_like_a_working_salon(): void
    {
        // Scheduler chạy CLI: không bị guard SQL chặn
        DemoMode::forceGuard(false);
        $done = $this->occupy($this->nam, '2026-10-05 09:00', 30);
        $running = $this->occupy($this->trang, '2026-10-05 09:45', 45);
        $this->travelTo(Carbon::parse('2026-10-05 10:00'));

        $this->artisan('demo:tick')->assertSuccessful();

        $this->assertSame(BookingStatus::Completed, $done->fresh()->status);
        $this->assertNotNull($done->fresh()->paid_at);
        $this->assertSame(BookingStatus::InProgress, $running->fresh()->status);
    }

    public function test_nothing_changes_when_demo_mode_is_off(): void
    {
        config(['demo.enabled' => false]);

        Customer::create(['name' => 'Khách mới', 'phone' => '0912000999']);
        $this->get('/')->assertOk()->assertDontSee('id="dmw"', false);
        $this->assertNull(DemoMode::credentials('admin'));
    }
}
