<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'name', 'slug', 'title', 'avatar', 'bio', 'phone', 'is_bookable', 'sort_order', 'is_active'])]
class Staff extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_bookable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'staff_service')->withPivot(['custom_price', 'custom_duration_minutes']);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class);
    }

    public function timeOffs(): HasMany
    {
        return $this->hasMany(StaffTimeOff::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Thợ đang làm và nhận lịch online */
    #[Scope]
    protected function bookable(Builder $query): void
    {
        $query->where('is_active', true)->where('is_bookable', true);
    }
}
