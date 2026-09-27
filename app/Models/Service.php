<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id', 'name', 'slug', 'short_description', 'description', 'image',
    'price', 'price_max', 'duration_minutes', 'buffer_minutes', 'is_active', 'is_featured', 'sort_order',
])]
class Service extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'price_max' => 'integer',
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'staff_service')->withPivot(['custom_price', 'custom_duration_minutes']);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
