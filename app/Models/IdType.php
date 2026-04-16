<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdType extends Model
{
    protected $table = 'id_types';

    protected $guarded = [];

    public function employeeIds(): HasMany
    {
        return $this->hasMany(EmployeeId::class, 'id_type_id');
    }
}