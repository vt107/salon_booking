<?php

namespace App\Services\Qr;

use App\Models\QrCode;
use App\Support\ShopInfo;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\Font;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Label\Margin\Margin;
use Endroid\QrCode\QrCode as EndroidQrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/** Ảnh mã QR đặt lịch để in (poster, tờ rơi) hoặc đăng online */
class QrImage
{
    private const INK = [31, 25, 22];

    public function __construct(private ShopInfo $shop) {}

    /** PNG để in: có dòng chữ tên tiệm và lời mời bên dưới */
    public function png(QrCode $qr, int $size = 1000): string
    {
        $label = new Label(
            text: $this->shop->name().' · Quét để đặt lịch',
            font: new Font(resource_path('fonts/BeVietnamPro-Medium.ttf'), (int) round($size / 28)),
            margin: new Margin(0, 10, (int) round($size / 25), 10),
            textColor: new Color(...self::INK),
        );

        return (new PngWriter)->write($this->qr($qr, $size), label: $label)->getString();
    }

    public function svg(QrCode $qr): string
    {
        return (new SvgWriter)->write($this->qr($qr, 600))->getString();
    }

    /** Ảnh nhỏ nhúng thẳng vào trang admin */
    public function dataUri(QrCode $qr, int $size = 240): string
    {
        return (new PngWriter)->write($this->qr($qr, $size))->getDataUri();
    }

    private function qr(QrCode $qr, int $size): EndroidQrCode
    {
        return new EndroidQrCode(
            data: $qr->url(),
            // Mức M: vẫn quét được khi poster bị trầy / dính bẩn một phần
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: (int) round($size / 20),
            foregroundColor: new Color(...self::INK),
        );
    }
}
