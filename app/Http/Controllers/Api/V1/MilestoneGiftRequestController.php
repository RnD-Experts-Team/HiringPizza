<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MilestoneGiftRequestStoreRequest;
use App\Http\Requests\Api\V1\MilestoneGiftRatingRequest;
use App\Http\Requests\Api\V1\MilestoneGiftDecisionRequest;
use App\Http\Requests\Api\V1\MilestoneGiftFinalStatusRequest;
use App\Models\MilestoneGiftRequest;
use App\Services\MilestoneGiftWorkflowService;
use Illuminate\Http\JsonResponse;

class MilestoneGiftRequestController extends Controller
{
    public function __construct(
        private readonly MilestoneGiftWorkflowService $workflowService
    ) {
    }

    public function store(MilestoneGiftRequestStoreRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $giftRequest = $this->workflowService->create($store, $request->validated());

        return response()->json(['data' => $giftRequest], 201);
    }

    public function submitRating(MilestoneGiftRatingRequest $request, string $storeNumber, MilestoneGiftRequest $milestoneGiftRequest): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        if ($milestoneGiftRequest->store_id !== $store->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $rating = $this->workflowService->submitRating($milestoneGiftRequest, $request->validated());

        return response()->json(['data' => $rating], 201);
    }

    public function recordDecision(MilestoneGiftDecisionRequest $request, string $storeNumber, MilestoneGiftRequest $milestoneGiftRequest): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        if ($milestoneGiftRequest->store_id !== $store->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $decision = $this->workflowService->recordGiftDecision($milestoneGiftRequest, $request->validated());

        return response()->json(['data' => $decision], 200);
    }

    public function setFinalStatus(MilestoneGiftFinalStatusRequest $request, string $storeNumber, MilestoneGiftRequest $milestoneGiftRequest): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        if ($milestoneGiftRequest->store_id !== $store->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $finalStatus = $this->workflowService->setFinalStatus($milestoneGiftRequest, $request->validated());

        return response()->json(['data' => $finalStatus], 200);
    }
}
