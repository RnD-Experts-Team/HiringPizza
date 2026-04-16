<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAvailabilityDay extends Model
{
    protected $table = 'employee_availability_days';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'shift_type' => ShiftType::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function times(): HasMany
    {
        return $this->hasMany(EmployeeAvailabilityTime::class, 'availability_day_id');
    }
}