<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeMetricColumn extends Model
{
    protected $table = 'employee_metric_columns';

    protected $guarded = [];

    public function values(): HasMany
    {
        return $this->hasMany(EmployeeMetricValue::class, 'column_id');
    }
}
