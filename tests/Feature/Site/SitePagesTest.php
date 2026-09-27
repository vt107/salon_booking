<?php

namespace Tests\Feature\Site;

use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\BusinessHour;
use App\Models\QrCode;
use App\Models\Setting;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class SitePagesTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        Setting::set('shop.name', 'Salon Mây');
    }

    public function test_home_page(): void
    {
        $this->haircut->update(['is_featured' => true]);

        $this->get('/')->assertOk()->assertSee(['Salon Mây', 'Cắt tóc', 'Tuấn', 'Hôm nay mở cửa 08:30 – 20:30']);
    }

    public function test_price_list_groups_active_services_and_hides_inactive(): void
    {
        $this->coloring->update(['price_max' => 900_000]);
        $this->gelNails->update(['is_active' => false]);

        $this->get(route('prices'))
            ->assertOk()
            ->assertSee(['Cắt tóc', '100.000đ', '450.000đ – 900.000đ'])
            ->assertDontSee('Sơn gel');
    }

    public function test_team_page_lists_only_bookable_staff(): void
    {
        $this->trang->update(['is_bookable' => false]);

        $this->get(route('team'))->assertOk()->assertSee(['Tuấn', 'Nam'])->assertDontSee('Trang');
    }

    public function test_opening_hours_are_grouped_in_the_footer(): void
    {
        BusinessHour::where('day_of_week', 0)->update(['is_closed' => true]);

        $this->get('/')->assertSeeInOrder(['Thứ Hai – Thứ Bảy', '08:30 – 20:30', 'Chủ nhật', 'Nghỉ']);
    }

    public function test_qr_code_counts_the_scan_and_prefills_the_booking_form(): void
    {
        $voucher = Voucher::create(['code' => 'QR10', 'name' => 'QR', 'type' => VoucherType::Percent, 'value' => 10, 'scope' => VoucherScope::All]);
        $qr = QrCode::create(['code' => 'POSTER', 'name' => 'Poster', 'staff_id' => $this->nam->id, 'voucher_id' => $voucher->id]);

        $this->get('/q/POSTER')
            ->assertRedirect(route('booking.create', ['staff' => $this->nam->id, 'voucher' => 'QR10']))
            ->assertSessionHas('booking.qr_code_id', $qr->id);

        $this->assertSame(1, $qr->fresh()->scan_count);
    }

    public function test_unknown_or_inactive_qr_code_falls_back_to_the_booking_form(): void
    {
        QrCode::create(['code' => 'OLD', 'name' => 'Cũ', 'is_active' => false]);

        $this->get('/q/OLD')->assertRedirect(route('booking.create'));
        $this->get('/q/NOPE')->assertRedirect(route('booking.create'));
    }
}
