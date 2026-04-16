<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeFinancialInfo extends Model
{
    protected $table = 'employee_financial_infos';

    protected $guarded = [];

    protected $hidden = [
        'account_number',
        'routing_number',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'account_number' => 'encrypted',
            'routing_number' => 'encrypted',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}