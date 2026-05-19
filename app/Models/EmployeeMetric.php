<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeMetric extends Model
{
    protected $table = 'employee_metrics';

    protected $guarded = [];

    protected $casts = [
        'metric_date' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(EmployeeMetricValue::class, 'employee_metric_id');
    }
}
