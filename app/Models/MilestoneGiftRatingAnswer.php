<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MilestoneGiftRatingAnswer extends Model
{
    protected $table = 'milestone_gift_rating_answers';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(MilestoneGiftRating::class, 'milestone_gift_rating_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(MilestoneGiftQuestion::class, 'milestone_gift_question_id');
    }

    public function selectedOptions(): HasMany
    {
        return $this->hasMany(MilestoneGiftRatingAnswerOption::class, 'milestone_gift_rating_answer_id');
    }
}
