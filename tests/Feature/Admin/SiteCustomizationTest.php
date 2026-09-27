<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class SiteCustomizationTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        Setting::set('shop.name', 'Salon Mây');
    }

    public function test_website_works_with_defaults(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Salon Mây · Đặt lịch online</title>', false)
            ->assertSee('cho <em class="text-clay">chính mình</em>.', false)
            ->assertDontSee('noindex')
            ->assertDontSee('googletagmanager');
    }

    public function test_admin_customizes_the_website(): void
    {
        // File upload tạm của Livewire hết hạn nếu đồng hồ bị đẩy tới tương lai (buildSalon đóng băng ở 05/10)
        $this->travelBack();
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm([
                'shop.name' => 'Salon Mây',
                'shop.instagram' => 'https://instagram.com/salonmay',
                'shop.logo' => UploadedFile::fake()->image('logo.png', 200, 200),
                'site.og_image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
                'site.favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
                'site.accent_color' => '#1f5f5b',
                'site.home_title' => 'Salon Mây – Cắt tóc & Nail Quận 1',
                'site.home_description' => 'Đặt lịch cắt tóc, làm nail tại Quận 1 chỉ trong 1 phút.',
                'site.prices_title' => 'Giá dịch vụ',
                'site.hero_title' => 'Đẹp hơn *mỗi ngày*',
                'site.hero_subtitle' => 'Thợ tay nghề cao, không phải chờ.',
                'site.hero_cta' => 'Giữ chỗ ngay',
                'site.about' => 'Tiệm nhỏ, chăm chút từng khách.',
                'site.announcement_active' => true,
                'site.announcement' => 'Giảm 10% cho khách đặt online!',
                'site.ga_id' => 'G-ABC123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Salon Mây – Cắt tóc &amp; Nail Quận 1</title>', false)
            ->assertSee('<meta name="description" content="Đặt lịch cắt tóc, làm nail tại Quận 1 chỉ trong 1 phút.">', false)
            ->assertSee('Đẹp hơn <em class="text-clay">mỗi ngày</em>', false)
            ->assertSee(['Thợ tay nghề cao, không phải chờ.', 'Giữ chỗ ngay', 'Giảm 10% cho khách đặt online!', 'Tiệm nhỏ, chăm chút từng khách.', 'https://instagram.com/salonmay'])
            ->assertSee('--color-clay:#1f5f5b', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('rel="icon"', false)
            ->assertSee('gtag/js?id=G-ABC123', false);

        $this->get(route('prices'))->assertSee('<title>Giá dịch vụ · Salon Mây</title>', false);
    }

    public function test_admin_text_cannot_inject_html(): void
    {
        Setting::set('site.hero_title', '<script>alert(1)</script> *xin chào*');

        $this->get('/')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; <em class="text-clay">xin chào</em>', false);
    }

    public function test_accent_color_must_keep_buttons_readable(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm(['shop.name' => 'Salon Mây', 'site.accent_color' => '#f7c6b5'])
            ->call('save')
            ->assertHasFormErrors(['site.accent_color']);

        $this->assertTrue(SiteSettings::accentIsReadable(SiteSettings::DEFAULT_ACCENT));
        $this->assertFalse(SiteSettings::accentIsReadable('#f7c6b5'));
    }

    public function test_noindex_hides_the_site_from_search_engines(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('prices'));

        Setting::set('site.noindex', true);

        $this->get('/')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->get('/sitemap.xml')->assertDontSee(route('prices'));
    }

    public function test_private_booking_page_is_never_indexed(): void
    {
        $booking = app(BookingService::class)->create($this->bookingData('2026-10-05 10:00'));

        $this->get($booking->manageUrl())->assertSee('content="noindex, nofollow"', false);
    }
}
