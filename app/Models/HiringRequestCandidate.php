<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HiringRequestCandidate extends Model
{
    protected $table = 'hiring_request_candidates';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function hiringRequest(): BelongsTo
    {
        return $this->belongsTo(HiringRequest::class);
    }
}
