<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\EmployeeWorkflowService;
use App\Services\LaborReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    /**
     * The Labor report for any stores over any inclusive range: stores[] (store
     * numbers, or stores[]=all), start_date and end_date.
     */
    public function range(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stores' => ['required', 'array', 'min:1'],
            'stores.*' => ['required', 'string'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $requested = array_values(array_unique($validated['stores']));

        if (in_array('all', $requested, true)) {
            $stores = Store::query()->get();
        } else {
            $stores = Store::query()->whereIn('store_number', $requested)->get();
            $unknown = array_diff($requested, $stores->pluck('store_number')->all());

            if ($unknown !== []) {
                throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
            }
        }

        return response()->json(
            $this->laborReportService->getRangeReport($stores, $validated['start_date'], $validated['end_date'])
        );
    }
}
