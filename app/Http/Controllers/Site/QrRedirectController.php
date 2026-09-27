<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use Illuminate\Http\RedirectResponse;

/**
 * Khách quét mã QR (poster, fanpage, QR riêng của thợ): ghi nhận lượt quét
 * và mở form đặt lịch với thợ / dịch vụ / voucher điền sẵn.
 */
class QrRedirectController extends Controller
{
    public function __invoke(string $code): RedirectResponse
    {
        $qr = QrCode::with('voucher')->where('code', $code)->where('is_active', true)->first();

        if (! $qr) {
            return redirect()->route('booking.create');
        }

        $qr->increment('scan_count');
        session(['booking.qr_code_id' => $qr->id]);

        return redirect()->route('booking.create', array_filter([
            'service' => $qr->service_id,
            'staff' => $qr->staff_id,
            'voucher' => $qr->voucher?->code,
        ]));
    }
}
