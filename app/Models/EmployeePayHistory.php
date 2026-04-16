<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayHistory extends Model
{
    protected $table = 'employee_pay_histories';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'base_pay' => 'decimal:2',
            'performance_pay' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}