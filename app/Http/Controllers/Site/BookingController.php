<?php

namespace App\Http\Controllers\Site;

use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\BookingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function create(Request $request): View
    {
        return view('site.booking.create', [
            'service' => $request->integer('service') ?: null,
            'staff' => $request->integer('staff') ?: null,
            'voucher' => $request->string('voucher')->limit(32, '')->value() ?: null,
        ]);
    }

    /** Trang khách xem / hủy lịch, mở từ link có chữ ký */
    public function show(Booking $booking, BookingSettings $settings): View
    {
        $booking->load(['items', 'staff', 'customer', 'voucher']);

        return view('site.booking.show', [
            'booking' => $booking,
            'canCancel' => $this->canCancel($booking, $settings),
            'cancelDeadlineHours' => $settings->cancelDeadlineHours(),
            'cancelUrl' => URL::signedRoute('booking.cancel', ['booking' => $booking->code]),
        ]);
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        try {
            $bookings->cancel($booking, CancelledBy::Customer, $booking->customer, $request->input('reason') ?: null);
        } catch (BookingException $e) {
            return redirect($booking->manageUrl())->with('error', $e->getMessage());
        }

        return redirect($booking->manageUrl())->with('success', 'Đã hủy lịch hẹn. Hẹn gặp bạn lần sau!');
    }

    private function canCancel(Booking $booking, BookingSettings $settings): bool
    {
        return match ($booking->status) {
            BookingStatus::Pending => true,
            BookingStatus::Confirmed => now()->addHours($settings->cancelDeadlineHours())->lte($booking->start_at),
            default => false,
        };
    }
}
