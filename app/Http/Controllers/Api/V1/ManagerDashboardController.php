<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EmployeeWorkflowService;
use App\Services\ManagerDashboardService;
use Illuminate\Http\JsonResponse;

class ManagerDashboardController extends Controller
{
    public function __construct(
        private readonly EmployeeWorkflowService $workflowService,
        private readonly ManagerDashboardService $dashboardService,
    ) {
    }

    public function show(string $storeNumber, string $date): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        return response()->json($this->dashboardService->getDashboard($store, $date));
    }
}
