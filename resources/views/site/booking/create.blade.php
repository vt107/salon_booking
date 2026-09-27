<x-layouts.site title="Đặt lịch">
    <section class="mx-auto max-w-6xl px-5 pt-10 md:pt-14">
        <p class="eyebrow">Đặt lịch</p>
        <h1 class="font-display mt-3 text-4xl font-medium tracking-tight md:text-5xl">Giữ chỗ cho bạn</h1>
        <livewire:booking-wizard :service="$service" :staff="$staff" :voucher="$voucher" />
    </section>
</x-layouts.site>
