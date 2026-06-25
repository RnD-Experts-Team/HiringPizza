<?php

namespace App\Models;

use App\Enums\MilestoneGiftMilestone;
use App\Enums\MilestoneGiftStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MilestoneGiftRequest extends Model
{
    protected $table = 'milestone_gift_requests';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'milestone' => MilestoneGiftMilestone::class,
            'stage' => MilestoneGiftStage::class,
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

    public function rating(): HasOne
    {
        return $this->hasOne(MilestoneGiftRating::class);
    }

    public function decision(): HasOne
    {
        return $this->hasOne(MilestoneGiftDecision::class);
    }

    public function finalStatus(): HasOne
    {
        return $this->hasOne(MilestoneGiftFinalStatus::class);
    }
}
