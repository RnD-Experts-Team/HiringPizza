<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaritalStatus extends Model
{
    protected $table = 'marital_statuses';

    protected $guarded = [];

    public function employeeMaritals(): HasMany
    {
        return $this->hasMany(EmployeeMarital::class);
    }
}