<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeId extends Model
{
    protected $table = 'employee_ids';

    protected $guarded = [];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function idType(): BelongsTo
    {
        return $this->belongsTo(IdType::class, 'id_type_id');
    }
}