<?php

namespace App\Models;

use App\Enums\Religion;
use App\Enums\Race;
use App\Enums\TShirtSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeObsession extends Model
{
    protected $table = 'emp_obsession';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            't_shirt' => TShirtSize::class,
            'religion' => Religion::class,
            'race' => Race::class,
            'birth_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}