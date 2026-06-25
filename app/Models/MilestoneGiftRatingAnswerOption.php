<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneGiftRatingAnswerOption extends Model
{
    protected $table = 'milestone_gift_rating_answer_options';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(MilestoneGiftRatingAnswer::class, 'milestone_gift_rating_answer_id');
    }

    public function questionOption(): BelongsTo
    {
        return $this->belongsTo(MilestoneGiftQuestionOption::class, 'milestone_gift_question_option_id');
    }
}
