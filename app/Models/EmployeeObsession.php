<?php

namespace App\Models;

use App\Enums\Religion;
use App\Enums\Race;
use App\Enums\TShirtSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeObsession extends Model
{
    protected $table = 'employee_obsessions';

    protected $guarded = [];

    protected $appends = ['image_url'];

    protected function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }

        $secure = str_starts_with((string) config('app.url'), 'https://') ? true : null;

        return asset('storage/' . $this->image_path, $secure);
    }

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