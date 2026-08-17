<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $table = 'stores';

    // Replicated from pizzasys — the pk arrives in the event and is never
    // generated here. Without this, Eloquent overwrites $store->id with
    // lastInsertId() (= 0) after create(), poisoning every FK written off
    // the in-memory model.
    public $incrementing = false;

    protected $guarded = [];
    protected $fillable = ['id', 'store_number'];
    public function employeeStores(): HasMany
    {
        return $this->hasMany(EmployeeStore::class);
    }
}