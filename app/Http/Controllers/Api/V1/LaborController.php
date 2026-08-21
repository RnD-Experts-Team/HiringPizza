<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EmployeeWorkflowService;
use App\Services\LaborReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaborController extends Controller
{
    public function __construct(
        private readonly EmployeeWorkflowService $workflowService,
        private readonly LaborReportService $laborReportService,
    ) {
    }

    public function show(Request $request, string $storeNumber, string $date): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        $trendWeeks = $request->query('trend_weeks');

        return response()->json(
            $this->laborReportService->getLaborReport($store, $date, $trendWeeks !== null ? (int) $trendWeeks : null)
        );
    }
}
