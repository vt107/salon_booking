<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Cấu hình dạng key-value, truy cập bằng "group.key", ví dụ Setting::get('booking.slot_interval_minutes').
 */
#[Fillable(['group', 'key', 'value'])]
class Setting extends Model
{
    private const CACHE_KEY = 'settings.all';

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        return static::cached()[$name] ?? $default;
    }

    public static function set(string $name, mixed $value): void
    {
        [$group, $key] = explode('.', $name, 2);

        static::updateOrCreate(['group' => $group, 'key' => $key], ['value' => $value]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->get()
            ->mapWithKeys(fn (self $setting) => ["{$setting->group}.{$setting->key}" => $setting->value])
            ->all());
    }
}
