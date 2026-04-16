<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $table = 'store';

    public $timestamps = false;

    protected $guarded = [];

    public function employeeStores(): HasMany
    {
        return $this->hasMany(EmployeeStore::class);
    }
}