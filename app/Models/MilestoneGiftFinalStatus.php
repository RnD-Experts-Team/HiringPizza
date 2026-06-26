<?php

namespace App\Models;

use App\Enums\MilestoneGiftFinalStatusType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneGiftFinalStatus extends Model
{
    protected $table = 'milestone_gift_final_statuses';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MilestoneGiftFinalStatusType::class,
            'confirmation_date' => 'date',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MilestoneGiftRequest::class, 'milestone_gift_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
