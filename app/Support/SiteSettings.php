<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * Nội dung và SEO của website khách, admin sửa ở Cài đặt → Website & SEO.
 * Mọi giá trị đều có mặc định: web vẫn hiển thị tốt khi admin chưa điền gì.
 */
class SiteSettings
{
    /** Màu nhấn mặc định (đất nung), khớp resources/css/app.css */
    public const DEFAULT_ACCENT = '#b4533a';

    /** Nền giấy của web: màu nhấn phải đủ tương phản với nền này để làm nền nút chữ trắng */
    private const PAPER = '#fcfaf6';

    public const DEFAULTS = [
        'hero_eyebrow' => 'Đặt lịch online · không cần chờ',
        'hero_title' => 'Dành một giờ cho *chính mình*.',
        'hero_subtitle' => 'Chọn dịch vụ, khung giờ và người thợ bạn tin tưởng. Tiệm xác nhận lịch trong ít phút.',
        'hero_cta' => 'Đặt lịch ngay',
        'cta_title' => 'Giữ chỗ trước, đến là được phục vụ ngay.',
        'cta_button' => 'Chọn giờ còn trống',
        'about' => null,
        'announcement' => null,
        'announcement_active' => false,
        'accent_color' => null,
        'favicon' => null,
        'og_image' => null,
        'site_title' => null,
        'home_title' => null,
        'home_description' => null,
        'prices_title' => 'Bảng giá',
        'prices_description' => null,
        'team_title' => 'Đội ngũ',
        'team_description' => null,
        'ga_id' => null,
        'fb_pixel_id' => null,
        'noindex' => false,
    ];

    public function __construct(private ShopInfo $shop) {}

    public function get(string $key): mixed
    {
        $value = Setting::get("site.{$key}");

        return blank($value) ? self::DEFAULTS[$key] : $value;
    }

    /** Hậu tố trên tab trình duyệt: "Bảng giá · Salon Mây" */
    public function siteTitle(): string
    {
        return $this->get('site_title') ?? $this->shop->name();
    }

    /**
     * Tiêu đề + mô tả SEO cho từng trang.
     *
     * @return array{title: string, description: string}
     */
    public function seo(string $page): array
    {
        $fallbackDescription = 'Đặt lịch tại '.$this->shop->name().' chỉ trong một phút: chọn dịch vụ, giờ và người thợ bạn thích.';

        return match ($page) {
            'home' => [
                'title' => $this->get('home_title') ?? $this->siteTitle().' · Đặt lịch online',
                'description' => $this->get('home_description') ?? $fallbackDescription,
            ],
            'prices', 'team' => [
                'title' => $this->get("{$page}_title").' · '.$this->siteTitle(),
                'description' => $this->get("{$page}_description") ?? $fallbackDescription,
            ],
            default => [
                'title' => ($page !== '' ? $page.' · ' : '').$this->siteTitle(),
                'description' => $fallbackDescription,
            ],
        };
    }

    public function faviconUrl(): ?string
    {
        return $this->fileUrl($this->get('favicon'));
    }

    public function ogImageUrl(): ?string
    {
        return $this->fileUrl($this->get('og_image')) ?? $this->shop->logoUrl();
    }

    /** Thanh thông báo đầu trang, null nếu đang tắt */
    public function announcement(): ?string
    {
        return $this->get('announcement_active') ? $this->get('announcement') : null;
    }

    /**
     * Văn bản admin nhập, *chữ trong dấu sao* được nhấn mạnh bằng màu nhấn.
     * Escape trước rồi mới thêm thẻ <em>: admin không chèn được HTML.
     */
    public function emphasize(?string $text): HtmlString
    {
        $escaped = e((string) $text);

        return new HtmlString(preg_replace('/\*(.+?)\*/u', '<em class="text-clay">$1</em>', $escaped));
    }

    /** CSS ghi đè màu nhấn (và 2 sắc độ phái sinh) nếu admin đổi màu */
    public function accentCss(): ?string
    {
        $color = $this->get('accent_color');

        if (! $color || strcasecmp($color, self::DEFAULT_ACCENT) === 0 || ! self::isHex($color)) {
            return null;
        }

        return ":root{--color-clay:{$color};--color-clay-dark:color-mix(in oklab,{$color} 78%,black);--color-clay-soft:color-mix(in oklab,{$color} 18%,white)}";
    }

    /** Màu nhấn dùng làm nền nút chữ sáng: cần tương phản ≥ 4.5:1 (WCAG AA) */
    public static function accentIsReadable(string $hex): bool
    {
        return self::isHex($hex) && self::contrast($hex, self::PAPER) >= 4.5;
    }

    public static function contrast(string $a, string $b): float
    {
        [$la, $lb] = [self::luminance($a), self::luminance($b)];

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function isHex(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair) {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function fileUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
