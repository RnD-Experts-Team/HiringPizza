<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SeparationRequestStoreRequest;
use App\Http\Requests\Api\V1\SeparationRequestDecisionRequest;
use App\Models\SeparationRequest;
use App\Services\SeparationRequestWorkflowService;
use Illuminate\Http\JsonResponse;

class SeparationRequestController extends Controller
{
    public function __construct(
        private readonly SeparationRequestWorkflowService $workflowService
    ) {
    }

    /**
     * Create a new separation request for an employee
     * This initiates the separation workflow - either termination or resignation
     */
    public function store(SeparationRequestStoreRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $separationRequest = $this->workflowService->create($store, $request->validated());

        return response()->json(['data' => $separationRequest], 201);
    }

    /**
     * Make a decision on a separation request
     * Decision can be 'rejected' or 'completed'
     * When completed, employee status is updated to terminated/resigned and status history is recorded
     */
    public function decide(SeparationRequestDecisionRequest $request, string $storeNumber, SeparationRequest $separationRequest): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        // Verify request belongs to this store
        if ($separationRequest->store_id !== $store->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $decision = $this->workflowService->makeDecision($separationRequest, $request->validated());

        return response()->json(['data' => $decision], 201);
    }
}
