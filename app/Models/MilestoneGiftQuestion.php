<?php

namespace App\Models;

use App\Enums\MilestoneGiftQuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MilestoneGiftQuestion extends Model
{
    protected $table = 'milestone_gift_questions';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'question_type' => MilestoneGiftQuestionType::class,
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(MilestoneGiftQuestionOption::class)->orderBy('sort_order');
    }
}
