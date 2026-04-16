<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $table = 'positions';

    protected $guarded = [];

    public function employeePositions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class);
    }
}