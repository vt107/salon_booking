<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Filament\Pages\Schedule;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Staff\RelationManagers\TimeOffsRelationManager;
use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\BusinessHour;
use App\Models\ClosedDay;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
    }

    /**
     * Trang => các vai trò được vào.
     *
     * @return array<string, array{\Closure, list<UserRole>}>
     */
    public static function pages(): array
    {
        $all = [UserRole::Admin, UserRole::Manager, UserRole::Staff];
        $managers = [UserRole::Admin, UserRole::Manager];

        return [
            'dashboard' => [fn () => '/admin', $all],
            'lịch theo ngày' => [fn () => Schedule::getUrl(), $all],
            'lịch hẹn' => [fn () => BookingResource::getUrl('index'), $all],
            'tạo lịch hẹn' => [fn () => BookingResource::getUrl('create'), $all],
            'khách hàng' => [fn () => CustomerResource::getUrl('index'), $all],
            'nhóm dịch vụ' => [fn () => ServiceCategoryResource::getUrl('index'), $managers],
            'dịch vụ' => [fn () => ServiceResource::getUrl('index'), $managers],
            'thêm dịch vụ' => [fn () => ServiceResource::getUrl('create'), $managers],
            'nhân viên' => [fn () => StaffResource::getUrl('index'), $managers],
            'thêm nhân viên' => [fn () => StaffResource::getUrl('create'), $managers],
            'voucher' => [fn () => VoucherResource::getUrl('index'), $managers],
            'thêm voucher' => [fn () => VoucherResource::getUrl('create'), $managers],
            'tài khoản' => [fn () => UserResource::getUrl('index'), [UserRole::Admin]],
            'cài đặt' => [fn () => Settings::getUrl(), [UserRole::Admin]],
        ];
    }

    /** @param  list<UserRole>  $allowed */
    #[DataProvider('pages')]
    public function test_page_access_by_role(\Closure $url, array $allowed): void
    {
        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get($url())
                ->assertStatus(in_array($role, $allowed, true) ? 200 : 403);
        }
    }

    public function test_inactive_user_cannot_enter_the_panel(): void
    {
        $this->actingAs(User::factory()->admin()->create(['is_active' => false]))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_edit_pages_render(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(ServiceResource::getUrl('edit', ['record' => $this->haircut]))->assertOk()->assertSee('Cắt tóc');
        $this->get(StaffResource::getUrl('edit', ['record' => $this->tuan]))->assertOk()->assertSee('Tuấn');
    }

    public function test_schedule_shows_bookings_per_staff_and_hides_cancelled_by_default(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->occupy($this->tuan, '2026-10-05 10:00', 30);
        $cancelled = $this->occupy($this->nam, '2026-10-05 11:00', 30, BookingStatus::Cancelled);

        Livewire::test(Schedule::class)
            ->assertSee(['Tuấn', 'Nam', '10:00–10:30', 'Khách cũ'])
            ->assertDontSee('11:00–11:30')
            ->set('showCancelled', true)
            ->assertSee('11:00–11:30')
            ->call('nextDay')
            ->assertSet('date', '2026-10-06')
            ->assertDontSee('10:00–10:30');

        $this->assertNotNull($cancelled);
    }

    public function test_settings_save_shop_info_opening_hours_and_closed_days(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm([
                'shop.name' => 'Salon Mây',
                'booking.min_lead_minutes' => 30,
                'hours.0.is_open' => false,
                'hours.1.open_time' => '09:00',
                'hours.1.close_time' => '19:00',
                'closed_days' => [['date' => '2026-10-10', 'reason' => 'Giỗ Tổ']],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Đã lưu cài đặt');

        $this->assertSame('Salon Mây', Setting::get('shop.name'));
        $this->assertSame(30, Setting::get('booking.min_lead_minutes'));
        $this->assertTrue(BusinessHour::firstWhere('day_of_week', 0)->is_closed);
        $this->assertSame('19:00:00', BusinessHour::firstWhere('day_of_week', 1)->close_time);
        $this->assertSame('Giỗ Tổ', ClosedDay::sole()->reason);
    }

    public function test_settings_reject_closing_before_opening(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm(['hours.2.open_time' => '18:00', 'hours.2.close_time' => '09:00'])
            ->call('save')
            ->assertHasFormErrors(['hours.2.close_time']);
    }

    public function test_manager_creates_a_service_scoped_voucher(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));

        Livewire::test(CreateVoucher::class)
            ->fillForm([
                'code' => 'nail20',
                'name' => 'Giảm nail',
                'type' => VoucherType::Percent->value,
                'value' => 20,
                'max_discount' => 100_000,
                'scope' => VoucherScope::Services->value,
                'services' => [$this->gelNails->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $voucher = Voucher::sole();
        $this->assertSame('NAIL20', $voucher->code);
        $this->assertSame([$this->gelNails->id], $voucher->services->modelKeys());
    }

    public function test_staff_services_are_attached_with_custom_price(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->nam, 'pageClass' => EditStaff::class])
            ->assertCanSeeTableRecords([$this->haircut])
            ->callTableAction('attach', data: ['recordId' => [$this->coloring->id], 'custom_price' => 500_000])
            ->assertHasNoTableActionErrors();

        $this->assertSame(500_000, $this->nam->services()->find($this->coloring->id)->pivot->custom_price);

        Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $this->nam, 'pageClass' => EditStaff::class])
            ->callTableAction('edit', $this->haircut, ['custom_price' => 120_000, 'custom_duration_minutes' => 40])
            ->assertHasNoTableActionErrors();

        $pivot = $this->nam->services()->find($this->haircut->id)->pivot;
        $this->assertSame([120_000, 40], [$pivot->custom_price, $pivot->custom_duration_minutes]);
        $this->assertSame(100_000, $this->haircut->fresh()->price);
    }

    public function test_time_off_overlapping_bookings_warns_the_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->occupy($this->tuan, '2026-10-06 10:00', 30);

        Livewire::test(TimeOffsRelationManager::class, ['ownerRecord' => $this->tuan, 'pageClass' => EditStaff::class])
            ->callTableAction('create', data: ['start_at' => '2026-10-06 09:00', 'end_at' => '2026-10-06 18:00', 'reason' => 'Ốm'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Có 1 lịch hẹn trong thời gian nghỉ này');

        $this->assertSame('Ốm', $this->tuan->timeOffs()->sole()->reason);
    }
}
