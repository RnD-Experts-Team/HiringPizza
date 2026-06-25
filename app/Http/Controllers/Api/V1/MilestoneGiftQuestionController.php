<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MilestoneGiftQuestionRequest;
use App\Models\MilestoneGiftQuestion;
use App\Models\MilestoneGiftQuestionOption;
use App\Services\MilestoneGiftWorkflowService;
use Illuminate\Http\JsonResponse;

class MilestoneGiftQuestionController extends Controller
{
    public function __construct(
        private readonly MilestoneGiftWorkflowService $workflowService
    ) {
    }

    // Global listing: all questions (active + inactive) for management use
    public function indexAll(): JsonResponse
    {
        $questions = $this->workflowService->allQuestions();

        return response()->json(['data' => $questions]);
    }

    // Store-scoped: returns global questions + this store's own questions
    public function index(string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $questions = $this->workflowService->questionsForStore($store);

        return response()->json(['data' => $questions]);
    }

    // Global management endpoints below (no storeNumber parameter)

    public function store(MilestoneGiftQuestionRequest $request): JsonResponse
    {
        $question = $this->workflowService->createQuestion($request->validated());

        return response()->json(['data' => $question->load('options')], 201);
    }

    public function update(MilestoneGiftQuestionRequest $request, MilestoneGiftQuestion $question): JsonResponse
    {
        $question = $this->workflowService->updateQuestion($question, $request->validated());

        return response()->json(['data' => $question]);
    }

    public function destroy(MilestoneGiftQuestion $question): JsonResponse
    {
        $this->workflowService->deactivateQuestion($question);

        return response()->json(null, 204);
    }

    public function storeOption(MilestoneGiftQuestionRequest $request, MilestoneGiftQuestion $question): JsonResponse
    {
        $option = $this->workflowService->addOption($question, $request->validated());

        return response()->json(['data' => $option], 201);
    }

    public function updateOption(MilestoneGiftQuestionRequest $request, MilestoneGiftQuestion $question, MilestoneGiftQuestionOption $option): JsonResponse
    {
        if ($option->milestone_gift_question_id !== $question->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $option = $this->workflowService->updateOption($option, $request->validated());

        return response()->json(['data' => $option]);
    }

    public function destroyOption(MilestoneGiftQuestion $question, MilestoneGiftQuestionOption $option): JsonResponse
    {
        if ($option->milestone_gift_question_id !== $question->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->workflowService->removeOption($option);

        return response()->json(null, 204);
    }
}
