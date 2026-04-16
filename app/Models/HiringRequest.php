<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HiringRequest extends Model
{
    protected $table = 'hiring_requests';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'desired_start_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(HiringRequestCandidate::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(HiringRequestPosition::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(HiringRequestDecision::class);
    }
}
