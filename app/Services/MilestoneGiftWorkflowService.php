<?php

namespace App\Services;

use App\Enums\MilestoneGiftFinalStatusType;
use App\Enums\MilestoneGiftStage;
use App\Models\Employee;
use App\Models\MilestoneGiftDecision;
use App\Models\MilestoneGiftFinalStatus;
use App\Models\MilestoneGiftQuestion;
use App\Models\MilestoneGiftQuestionOption;
use App\Models\MilestoneGiftRating;
use App\Models\MilestoneGiftRatingAnswer;
use App\Models\MilestoneGiftRatingAnswerOption;
use App\Models\MilestoneGiftRequest;
use App\Models\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MilestoneGiftWorkflowService
{
    public function resolveStoreByNumber(string $storeNumber): Store
    {
        return Store::query()->where('store_number', $storeNumber)->firstOrFail();
    }

    public function create(Store $store, array $data): MilestoneGiftRequest
    {
        return DB::transaction(function () use ($store, $data) {
            $employee = Employee::findOrFail($data['employee_id']);

            $this->assertEmployeeInStore($store, $employee);

            $request = MilestoneGiftRequest::query()->create([
                'store_id' => $store->id,
                'user_id' => Auth::id(),
                'employee_id' => $employee->id,
                'milestone' => $data['milestone'],
                'milestone_other' => $data['milestone_other'] ?? null,
                'stage' => MilestoneGiftStage::Created,
            ]);

            return $this->load($request);
        });
    }

    public function submitRating(MilestoneGiftRequest $giftRequest, array $data): MilestoneGiftRating
    {
        return DB::transaction(function () use ($giftRequest, $data) {
            $this->assertStage($giftRequest, MilestoneGiftStage::Created);

            $rating = MilestoneGiftRating::query()->create([
                'milestone_gift_request_id' => $giftRequest->id,
                'user_id' => Auth::id(),
                'additional_comments' => $data['additional_comments'] ?? null,
                'submitted_at' => now(),
            ]);

            foreach ($data['answers'] as $answerData) {
                $answer = MilestoneGiftRatingAnswer::query()->create([
                    'milestone_gift_rating_id' => $rating->id,
                    'milestone_gift_question_id' => $answerData['question_id'],
                ]);

                $optionIds = (array) $answerData['option_ids'];
                foreach ($optionIds as $optionId) {
                    MilestoneGiftRatingAnswerOption::query()->create([
                        'milestone_gift_rating_answer_id' => $answer->id,
                        'milestone_gift_question_option_id' => $optionId,
                    ]);
                }
            }

            $giftRequest->update(['stage' => MilestoneGiftStage::Rating]);

            return $rating->load(['answers.question', 'answers.selectedOptions.questionOption', 'user']);
        });
    }

    public function recordGiftDecision(MilestoneGiftRequest $giftRequest, array $data): MilestoneGiftDecision
    {
        return DB::transaction(function () use ($giftRequest, $data) {
            $this->assertStage($giftRequest, MilestoneGiftStage::Rating, MilestoneGiftStage::GiftDecision);

            $isCancelled = (bool) ($data['is_cancelled'] ?? false);
            $newStage = $isCancelled ? MilestoneGiftStage::Cancelled : MilestoneGiftStage::GiftDecision;

            $decision = MilestoneGiftDecision::query()->updateOrCreate(
                ['milestone_gift_request_id' => $giftRequest->id],
                [
                    'user_id' => Auth::id(),
                    'is_cancelled' => $isCancelled,
                    'cancellation_reason' => $isCancelled ? ($data['cancellation_reason'] ?? null) : null,
                    'gift_description' => !$isCancelled ? ($data['gift_description'] ?? null) : null,
                    'gift_cost' => !$isCancelled ? ($data['gift_cost'] ?? null) : null,
                    'delivery_date' => !$isCancelled ? ($data['delivery_date'] ?? null) : null,
                    'sent_to_store' => !$isCancelled ? ($data['sent_to_store'] ?? null) : null,
                    'decided_at' => now(),
                ]
            );

            $giftRequest->update(['stage' => $newStage]);

            return $decision->load('user');
        });
    }

    public function setFinalStatus(MilestoneGiftRequest $giftRequest, array $data): MilestoneGiftFinalStatus
    {
        return DB::transaction(function () use ($giftRequest, $data) {
            $this->assertStage(
                $giftRequest,
                MilestoneGiftStage::GiftDecision,
                MilestoneGiftStage::FinalStatus,
                MilestoneGiftStage::Closed,
            );

            $close = (bool) ($data['close'] ?? false);
            $newStage = $close ? MilestoneGiftStage::Closed : MilestoneGiftStage::FinalStatus;

            $finalStatus = MilestoneGiftFinalStatus::query()->updateOrCreate(
                ['milestone_gift_request_id' => $giftRequest->id],
                [
                    'user_id' => Auth::id(),
                    'status' => $data['status'],
                    'status_other_reason' => $data['status_other_reason'] ?? null,
                    'confirmation_date' => $data['confirmation_date'],
                    'closing_notes' => $close ? ($data['closing_notes'] ?? null) : null,
                    'closed_at' => $close ? now() : null,
                ]
            );

            $giftRequest->update(['stage' => $newStage]);

            return $finalStatus->load('user');
        });
    }

    public function load(MilestoneGiftRequest $giftRequest): MilestoneGiftRequest
    {
        return $giftRequest->load([
            'store',
            'user',
            'employee',
            'rating.answers.question.options',
            'rating.answers.selectedOptions.questionOption',
            'rating.user',
            'decision.user',
            'finalStatus.user',
        ]);
    }

    public function allQuestions(): \Illuminate\Database\Eloquent\Collection
    {
        return MilestoneGiftQuestion::query()
            ->with('options')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function questionsForStore(Store $store): \Illuminate\Database\Eloquent\Collection
    {
        return MilestoneGiftQuestion::query()
            ->with('options')
            ->where('is_active', true)
            ->where(function ($q) use ($store) {
                $q->whereNull('store_id')->orWhere('store_id', $store->id);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function createQuestion(array $data): MilestoneGiftQuestion
    {
        return MilestoneGiftQuestion::query()->create([
            'store_id' => $data['store_id'] ?? null,
            'question_text' => $data['question_text'],
            'question_type' => $data['question_type'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function updateQuestion(MilestoneGiftQuestion $question, array $data): MilestoneGiftQuestion
    {
        $question->update(array_filter([
            'question_text' => $data['question_text'] ?? null,
            'question_type' => $data['question_type'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'is_active' => $data['is_active'] ?? null,
        ], fn($v) => $v !== null));

        return $question->load('options');
    }

    public function deactivateQuestion(MilestoneGiftQuestion $question): void
    {
        $question->update(['is_active' => false]);
    }

    public function addOption(MilestoneGiftQuestion $question, array $data): MilestoneGiftQuestionOption
    {
        return MilestoneGiftQuestionOption::query()->create([
            'milestone_gift_question_id' => $question->id,
            'option_text' => $data['option_text'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateOption(MilestoneGiftQuestionOption $option, array $data): MilestoneGiftQuestionOption
    {
        $option->update(array_filter([
            'option_text' => $data['option_text'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn($v) => $v !== null));

        return $option;
    }

    public function removeOption(MilestoneGiftQuestionOption $option): void
    {
        $option->delete();
    }

    protected function assertEmployeeInStore(Store $store, Employee $employee): void
    {
        $latestStoreId = $employee->stores()
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('store_id');

        if ($latestStoreId === null || (int) $latestStoreId !== $store->id) {
            throw new ModelNotFoundException(
                "Employee {$employee->id} is not assigned to store {$store->store_number}"
            );
        }
    }

    protected function assertStage(MilestoneGiftRequest $giftRequest, MilestoneGiftStage ...$allowedStages): void
    {
        if (!in_array($giftRequest->stage, $allowedStages, true)) {
            $allowed = implode(', ', array_map(fn($s) => $s->value, $allowedStages));
            throw new \LogicException(
                "Cannot perform this action on a milestone gift request in stage '{$giftRequest->stage->value}'. Allowed stage(s): {$allowed}."
            );
        }
    }
}
