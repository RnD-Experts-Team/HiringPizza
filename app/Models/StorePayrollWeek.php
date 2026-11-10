<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A store's total payroll and labor % for one business week (weeks before employee metrics). */
class StorePayrollWeek extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
