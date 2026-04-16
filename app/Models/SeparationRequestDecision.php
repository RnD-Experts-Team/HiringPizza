<?php

namespace App\Models;

use App\Enums\RequestDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeparationRequestDecision extends Model
{
    protected $table = 'separation_request_decisions';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decision' => RequestDecision::class,
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function separationRequest(): BelongsTo
    {
        return $this->belongsTo(SeparationRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
