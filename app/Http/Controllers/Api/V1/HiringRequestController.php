<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HiringRequestStoreRequest;
use App\Http\Requests\Api\V1\HiringRequestDecisionRequest;
use App\Models\HiringRequest;
use App\Services\HiringRequestWorkflowService;
use Illuminate\Http\JsonResponse;

class HiringRequestController extends Controller
{
    public function __construct(
        private readonly HiringRequestWorkflowService $workflowService
    ) {
    }

    /**
     * Create a new hiring request
     * Specifies how many employees are needed, desired start date, any pre-existing candidates,
     * and detailed position requirements (shift types, availability, notes)
     */
    public function store(HiringRequestStoreRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $hiringRequest = $this->workflowService->create($store, $request->validated());

        return response()->json(['data' => $hiringRequest], 201);
    }

    /**
     * Complete a hiring request by specifying which employees were hired
     * Once submitted, the hiring request is considered finished with completion_date recorded
     * All hired employees must already exist in the system
     */
    public function decide(HiringRequestDecisionRequest $request, string $storeNumber, HiringRequest $hiringRequest): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        // Verify request belongs to this store
        if ($hiringRequest->store_id !== $store->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $decision = $this->workflowService->makeDecision($hiringRequest, $request->validated());

        return response()->json(['data' => $decision], 201);
    }
}
