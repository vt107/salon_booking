<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Chế độ demo: "Đăng nhập nhanh" từ nút Demo. Đăng xuất phiên hiện tại rồi mở trang đăng nhập
 * của khu vực tương ứng với tài khoản đã điền sẵn (?demo=<key>).
 */
class DemoController extends Controller
{
    public function switch(Request $request, string $key): RedirectResponse
    {
        foreach (config('demo.portals', []) as $portal) {
            foreach ($portal['accounts'] ?? [] as $account) {
                if ($account['key'] !== $key) {
                    continue;
                }

                foreach (array_keys(config('auth.guards')) as $guard) {
                    if (config("auth.guards.{$guard}.driver") === 'session' && Auth::guard($guard)->check()) {
                        Auth::guard($guard)->logout();
                    }
                }

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $loginUrl = url($portal['login_url'] ?? $portal['url']);

                return redirect()->to($loginUrl.(str_contains($loginUrl, '?') ? '&' : '?').'demo='.urlencode($key));
            }
        }

        abort(404);
    }

    /** Khu vực "Trang lịch hẹn của khách": mở link có chữ ký của một lịch sắp tới (nhiều dịch vụ, còn hủy được) */
    public function booking(): RedirectResponse
    {
        $booking = Booking::where('status', BookingStatus::Confirmed)
            ->where('start_at', '>', now()->addDay())
            ->has('items', '>=', 2)
            ->orderBy('start_at')
            ->first()
            ?? Booking::where('start_at', '>', now())->orderBy('start_at')->firstOrFail();

        return redirect()->to($booking->manageUrl());
    }
}
