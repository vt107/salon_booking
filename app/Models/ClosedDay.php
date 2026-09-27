<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Ngày tiệm nghỉ (Tết, lễ, nghỉ đột xuất) */
#[Fillable(['date', 'reason'])]
class ClosedDay extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
