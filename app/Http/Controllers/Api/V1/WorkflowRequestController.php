<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WorkflowRequestIndexRequest;
use App\Services\EmployeeWorkflowService;
use App\Services\WorkflowRequestQueryService;
use Illuminate\Http\JsonResponse;

class WorkflowRequestController extends Controller
{
    public function __construct(
        private readonly EmployeeWorkflowService $workflowService,
        private readonly WorkflowRequestQueryService $queryService
    ) {
    }

    public function index(WorkflowRequestIndexRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $requests = $this->queryService->index($store, $request->validated());

        return response()->json($requests);
    }
}
