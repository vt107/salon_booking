<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Filament\Resources\QrCodes\Pages\ManageQrCodes;
use App\Models\QrCode;
use App\Models\User;
use App\Services\Qr\QrImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class QrCodeAdminTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_manager_creates_a_qr_code_for_a_staff_business_card(): void
    {
        Livewire::test(ManageQrCodes::class)
            ->callAction('create', ['name' => 'Danh thiếp Tuấn', 'code' => 'tuan01', 'staff_id' => $this->tuan->id, 'is_active' => true])
            ->assertHasNoActionErrors();

        $qr = QrCode::sole();
        $this->assertSame('TUAN01', $qr->code);
        $this->assertSame(url('/q/TUAN01'), $qr->url());
        $this->get('/q/TUAN01')->assertRedirect(route('booking.create', ['staff' => $this->tuan->id]));
    }

    public function test_generated_codes_avoid_ambiguous_characters(): void
    {
        foreach (range(1, 50) as $i) {
            $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{6}$/', QrCode::generateCode());
        }
    }

    public function test_table_shows_scans_bookings_and_revenue(): void
    {
        $qr = QrCode::create(['code' => 'QUAY', 'name' => 'Poster quầy', 'scan_count' => 8]);
        $qr->forceFill(['scan_count' => 8])->save();
        foreach ([BookingStatus::Completed, BookingStatus::Completed, BookingStatus::Cancelled] as $i => $status) {
            $booking = $this->occupy($this->tuan, '2026-10-0'.($i + 1).' 10:00', 30, $status);
            $booking->update(['qr_code_id' => $qr->id, 'source' => BookingSource::Qr, 'total' => 100_000]);
        }

        Livewire::test(ManageQrCodes::class)
            ->assertCanSeeTableRecords([$qr])
            ->assertSee(['Poster quầy', '38% lượt quét', '200.000đ']);
    }

    public function test_png_and_svg_downloads(): void
    {
        $qr = QrCode::create(['code' => 'QUAY', 'name' => 'Poster quầy']);

        Livewire::test(ManageQrCodes::class)
            ->callTableAction('png', $qr)
            ->assertFileDownloaded('qr-QUAY.png');

        $png = app(QrImage::class)->png($qr);
        $this->assertStringStartsWith("\x89PNG", $png);
        [$width, $height] = getimagesizefromstring($png);
        $this->assertSame(1100, $width); // 1000px mã + lề trắng 50px mỗi bên (vùng yên tĩnh để máy quét nhận)
        $this->assertGreaterThan($width, $height); // có dòng chữ bên dưới

        $this->assertStringContainsString('<svg', app(QrImage::class)->svg($qr));
    }

    public function test_warns_when_app_url_is_not_public(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        Livewire::test(ManageQrCodes::class)->assertSee('chỉ mở được trên máy này');

        config(['app.url' => 'https://salonmay.vn']);
        Livewire::test(ManageQrCodes::class)->assertDontSee('chỉ mở được trên máy này');
    }
}
