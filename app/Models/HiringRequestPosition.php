<?php

namespace App\Models;

use App\Enums\AvailabilityType;
use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HiringRequestPosition extends Model
{
    protected $table = 'hiring_request_positions';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'shift_type' => ShiftType::class,
            'availability_type' => AvailabilityType::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function hiringRequest(): BelongsTo
    {
        return $this->belongsTo(HiringRequest::class);
    }
}
