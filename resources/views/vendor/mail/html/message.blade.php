@inject('shop', 'App\Support\ShopInfo')
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="route('home')">
{{ $shop->name() }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ $shop->name() }}@if ($address = $shop->get('address')) · {{ $address }}@endif
@if ($phone = $shop->get('phone'))
<br>Hotline: {{ $phone }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
