<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EmployeeWorkflowService;
use App\Services\ManagerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Multi-store date-range report. Accepts stores[] (array of store numbers,
     * or stores[]=all) plus start_date and end_date as query parameters.
     * Returns the same four sections as reports() but for every requested store
     * over the given date range, keyed by store number.
     */
    public function reportsMultiStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stores'     => ['required', 'array', 'min:1'],
            'stores.*'   => ['required', 'string'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date'   => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        return response()->json(
            $this->dashboardService->getMultiStoreReports(
                $validated['stores'],
                $validated['start_date'],
                $validated['end_date'],
            )
        );
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
