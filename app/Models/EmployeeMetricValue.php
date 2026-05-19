<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeMetricValue extends Model
{
    protected $table = 'employee_metric_values';

    protected $guarded = [];

    protected $casts = [
        'value_numeric' => 'decimal:12',
    ];

    public function metric(): BelongsTo
    {
        return $this->belongsTo(EmployeeMetric::class, 'employee_metric_id');
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(EmployeeMetricColumn::class, 'column_id');
    }
}
