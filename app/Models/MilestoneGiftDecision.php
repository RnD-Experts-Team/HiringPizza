<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneGiftDecision extends Model
{
    protected $table = 'milestone_gift_decisions';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_cancelled' => 'boolean',
            'sent_to_store' => 'boolean',
            'gift_cost' => 'decimal:2',
            'delivery_date' => 'date',
            'decided_at' => 'datetime',
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
