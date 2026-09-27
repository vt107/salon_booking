@php
    use App\Enums\BookingStatus;
    use App\Support\Money;
    $longWeekdays = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
    $shop = app(\App\Support\ShopInfo::class);
    $statusStyle = match ($booking->status) {
        BookingStatus::Pending => 'bg-amber-100 text-amber-800',
        BookingStatus::Confirmed, BookingStatus::InProgress => 'bg-sage-soft text-sage',
        BookingStatus::Completed => 'bg-sand text-ink',
        default => 'bg-clay-soft text-clay-dark',
    };
    $headline = match ($booking->status) {
        BookingStatus::Pending => 'Đã nhận yêu cầu, tiệm sẽ xác nhận sớm.',
        BookingStatus::Confirmed => 'Lịch hẹn đã được xác nhận. Hẹn gặp bạn!',
        BookingStatus::InProgress => 'Bạn đang được phục vụ.',
        BookingStatus::Completed => 'Cảm ơn bạn đã ghé tiệm!',
        BookingStatus::Rejected => 'Rất tiếc, tiệm không nhận được lịch này.',
        BookingStatus::Cancelled => 'Lịch hẹn đã được hủy.',
        BookingStatus::NoShow => 'Lịch hẹn đã qua.',
    };
    $calendarUrl = 'https://calendar.google.com/calendar/render?'.http_build_query([
        'action' => 'TEMPLATE',
        'text' => $booking->items->pluck('service_name')->implode(', ').' · '.$shop->name(),
        'dates' => $booking->start_at->format('Ymd\THis').'/'.$booking->end_at->format('Ymd\THis'),
        'ctz' => config('app.timezone'),
        'location' => $shop->get('address'),
        'details' => 'Mã lịch hẹn: '.$booking->code,
    ]);
@endphp
<x-layouts.site title="Lịch hẹn {{ $booking->code }}">
    <section class="mx-auto max-w-2xl px-5 pt-12">
        @if (session('booking.just_created'))
            <div class="mb-8 text-center animate-rise">
                <p class="font-display text-5xl text-clay italic">Cảm ơn bạn!</p>
                <p class="mt-3 text-ink-soft">Hãy lưu lại trang này (hoặc email xác nhận) để xem và hủy lịch khi cần.</p>
            </div>
        @endif
        @if (session('success'))
            <p class="mb-6 rounded-2xl bg-sage-soft px-5 py-4 text-sage">{{ session('success') }}</p>
        @endif
        @if (session('error'))
            <p class="mb-6 rounded-2xl bg-clay-soft px-5 py-4 text-clay-dark">{{ session('error') }}</p>
        @endif

        {{-- Vé hẹn --}}
        <article class="overflow-hidden rounded-[2rem] border border-ink/10 bg-paper shadow-[0_30px_60px_-40px_rgba(31,25,22,0.35)]">
            <div class="flex items-start justify-between gap-4 p-7 md:p-9">
                <div>
                    <p class="text-xs tracking-[0.2em] text-ink-faint uppercase">Mã lịch hẹn</p>
                    <p class="font-display mt-1 text-3xl tracking-wide">{{ $booking->code }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm font-medium {{ $statusStyle }}">{{ $booking->status->getLabel() }}</span>
            </div>
            <p class="px-7 text-ink-soft md:px-9">{{ $headline }}
                @if ($booking->status_reason && in_array($booking->status, [BookingStatus::Rejected, BookingStatus::Cancelled], true))
                    <br><span class="text-sm">Lý do: {{ $booking->status_reason }}</span>
                @endif
            </p>

            <div class="relative my-7 border-t border-dashed border-ink/20">
                <span class="absolute -top-3 -left-3 size-6 rounded-full bg-cream"></span>
                <span class="absolute -top-3 -right-3 size-6 rounded-full bg-cream"></span>
            </div>

            <dl class="grid gap-6 px-7 pb-8 sm:grid-cols-2 md:px-9">
                <div>
                    <dt class="text-sm text-ink-faint">Thời gian</dt>
                    <dd class="font-display mt-1 text-2xl">{{ $booking->start_at->format('H:i') }} – {{ $booking->end_at->format('H:i') }}</dd>
                    <dd class="text-ink-soft">{{ $longWeekdays[$booking->start_at->dayOfWeek] }}, {{ $booking->start_at->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-ink-faint">Người thợ</dt>
                    <dd class="mt-2 flex items-center gap-3">
                        <x-staff-avatar :staff="$booking->staff" size="size-10" class="text-sm" />
                        <span>{{ $booking->staff->name }}</span>
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-ink-faint">Dịch vụ</dt>
                    <dd class="mt-2 space-y-1.5">
                        @foreach ($booking->items as $item)
                            <p class="flex items-baseline"><span>{{ $item->service_name }}</span><span class="leader"></span><span class="tabular-nums">{{ Money::format($item->price) }}</span></p>
                        @endforeach
                        @if ($booking->discount_amount)
                            <p class="flex items-baseline text-sage"><span>Giảm giá {{ $booking->voucher?->code }}</span><span class="leader"></span><span class="tabular-nums">-{{ Money::format($booking->discount_amount) }}</span></p>
                        @endif
                        <p class="flex items-baseline border-t border-ink/10 pt-2 font-medium"><span>Thanh toán tại tiệm</span><span class="leader"></span><span class="font-display text-xl tabular-nums">{{ Money::format($booking->total) }}</span></p>
                    </dd>
                </div>
                @if ($address = $shop->get('address'))
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-ink-faint">Địa chỉ</dt>
                        <dd class="mt-1">{{ $address }}
                            @if ($map = $shop->get('map_url')) · <a href="{{ $map }}" target="_blank" rel="noopener" class="text-clay underline underline-offset-4">Chỉ đường</a> @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </article>

        @if ($booking->status->isBlocking())
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <a href="{{ $calendarUrl }}" target="_blank" rel="noopener" class="btn-ghost">Thêm vào Google Calendar</a>
                @if ($phone = $shop->get('phone'))
                    <a href="tel:{{ $phone }}" class="btn-ghost">Gọi tiệm {{ $phone }}</a>
                @endif
            </div>
        @endif

        @if ($canCancel)
            <details class="group mt-10 rounded-2xl border border-ink/10 bg-paper/60 p-5">
                <summary class="cursor-pointer list-none text-center text-sm text-ink-soft group-open:mb-4">Không đến được? <span class="text-clay underline underline-offset-4">Hủy lịch hẹn</span></summary>
                <form method="POST" action="{{ $cancelUrl }}" class="space-y-3">
                    @csrf
                    <input type="text" name="reason" maxlength="200" class="field" placeholder="Lý do (không bắt buộc)">
                    <button type="submit" class="btn w-full bg-clay-soft text-clay-dark hover:bg-clay hover:text-paper" onclick="return confirm('Hủy lịch hẹn {{ $booking->code }}?')">Xác nhận hủy lịch</button>
                </form>
            </details>
        @elseif ($booking->status === BookingStatus::Confirmed)
            <p class="mt-10 text-center text-sm text-ink-soft">Đã quá thời hạn hủy online ({{ $cancelDeadlineHours }} giờ trước giờ hẹn). Vui lòng gọi cho tiệm nếu bạn không đến được.</p>
        @endif

        @if (! $booking->status->isBlocking())
            <div class="mt-10 text-center">
                <a href="{{ route('booking.create') }}" class="btn-primary" wire:navigate>Đặt lịch mới</a>
            </div>
        @endif
    </section>
</x-layouts.site>
