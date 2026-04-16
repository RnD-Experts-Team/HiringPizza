<?php

namespace App\Models;

use App\Enums\SeparationType;
use App\Enums\ResignationReason;
use App\Enums\TerminationReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeparationRequest extends Model
{
    protected $table = 'separation_requests';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'separation_type' => SeparationType::class,
            'termination_reason' => TerminationReason::class,
            'resignation_reason' => ResignationReason::class,
            'final_working_day' => 'date',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SeparationRequestAttachment::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(SeparationRequestDecision::class);
    }
}
