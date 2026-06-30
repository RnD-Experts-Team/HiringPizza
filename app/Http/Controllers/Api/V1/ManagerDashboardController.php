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

    public function averageHourlyPay(string $store, string $date): JsonResponse
    {
        return response()->json($this->dashboardService->getAverageHourlyPay($store, $date));
    }

    public function highHoursEmployees(string $store, string $date): JsonResponse
    {
        return response()->json($this->dashboardService->getHighHoursEmployees($store, $date));
    }

    /**
     * Single page-load endpoint: returns all three dashboard payloads in one
     * response so the manager dashboard page hits the API once instead of
     * three times. Each section keeps its own date window and structure.
     */
    public function reports(string $store, string $date): JsonResponse
    {
        $storeModel = $this->workflowService->resolveStoreByNumber($store);

        return response()->json([
            'manager-dashboard'    => $this->dashboardService->getDashboard($storeModel, $date),
            'high-hours-employees' => $this->dashboardService->getHighHoursEmployees($store, $date),
            'average-hourly-pay'   => $this->dashboardService->getAverageHourlyPay($store, $date),
            'weekly-labor'         => $this->dashboardService->getWeeklyLabor($store, $date),
        ]);
    }
}
