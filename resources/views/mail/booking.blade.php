@php
    use App\Support\Money;
    $weekdays = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
@endphp
<x-mail::message>
# {{ $heading }}

Chào {{ $customer->name }},

@foreach ($paragraphs as $paragraph)
{{ $paragraph }}

@endforeach
<x-mail::panel>
**Mã lịch hẹn: {{ $booking->code }}**<br>
{{ $booking->start_at->format('H:i') }} – {{ $booking->end_at->format('H:i') }}, {{ $weekdays[$booking->start_at->dayOfWeek] }} {{ $booking->start_at->format('d/m/Y') }}<br>
Người thợ: {{ $booking->staff->name }}
</x-mail::panel>

<x-mail::table>
| Dịch vụ | Giá |
|:--------|----:|
@foreach ($booking->items as $item)
| {{ $item->service_name }} | {{ Money::format($item->price) }} |
@endforeach
@if ($booking->discount_amount)
| Giảm giá {{ $booking->voucher?->code }} | -{{ Money::format($booking->discount_amount) }} |
@endif
| **Thanh toán tại tiệm** | **{{ Money::format($booking->total) }}** |
</x-mail::table>

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

Trân trọng,<br>
{{ app(\App\Support\ShopInfo::class)->name() }}
</x-mail::message>
