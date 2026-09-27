@props(['staff', 'size' => 'size-16'])
@php
    $tones = ['bg-clay-soft text-clay-dark', 'bg-sage-soft text-sage', 'bg-sand text-ink-soft'];
    $initials = collect(explode(' ', $staff->name))->map(fn ($w) => mb_substr($w, 0, 1))->take(-2)->implode('');
@endphp
@if ($staff->avatar)
    <img src="{{ Storage::disk('public')->url($staff->avatar) }}" alt="{{ $staff->name }}" {{ $attributes->class([$size, 'rounded-full object-cover']) }}>
@else
    <span {{ $attributes->class([$size, $tones[$staff->id % 3], 'inline-flex shrink-0 items-center justify-center rounded-full font-display text-xl']) }}>{{ $initials }}</span>
@endif
