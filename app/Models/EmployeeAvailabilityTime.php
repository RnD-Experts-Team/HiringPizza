<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAvailabilityTime extends Model
{
    protected $table = 'employee_availability_times';

    protected $guarded = [];

    public function availabilityDay(): BelongsTo
    {
        return $this->belongsTo(EmployeeAvailabilityDay::class, 'availability_day_id');
    }
}