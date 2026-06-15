<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EmployeeWorkflowService;
use App\Services\ManagerDashboardService;
use Illuminate\Http\JsonResponse;
use App\Models\EmployeeMetric;
use App\Models\EmployeeStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
        $day = CarbonImmutable::parse($date)->startOfDay();

        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $weekStartDate = $weekStart->toDateString();
        $weekEndDate = $weekEnd->toDateString();

        /*
         * Latest store assignment per employee as of the selected week end.
         * The route {store} is stores.store_number, not stores.id.
         */
        $latestEmployeeStores = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $weekEndDate)
            ->groupBy('employee_id');

        $metrics = EmployeeMetric::query()
            ->from('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->joinSub($latestEmployeeStores, 'latest_es', function ($join) {
                $join->on('latest_es.employee_id', '=', 'em.employee_id');
            })
            ->join('employee_stores as es', function ($join) {
                $join->on('es.employee_id', '=', 'latest_es.employee_id')
                    ->on('es.effective_date', '=', 'latest_es.effective_date');
            })
            ->join('stores as s', 's.id', '=', 'es.store_id')
            ->where('s.store_number', $store)
            ->whereBetween('em.metric_date', [$weekStartDate, $weekEndDate])
            ->whereIn('emv.column_id', [2, 3, 4, 23])
            ->selectRaw('AVG(CASE WHEN emv.column_id = 2 THEN emv.value_numeric END) as average_hourly_pay')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 2 THEN emv.value_numeric END) as maximum_hourly_pay')
            ->selectRaw('MIN(CASE WHEN emv.column_id = 2 THEN emv.value_numeric END) as minimum_hourly_pay')
            ->selectRaw('COALESCE(SUM(CASE WHEN emv.column_id = 4 THEN emv.value_numeric END), 0) as total_tips')
            ->selectRaw('COALESCE(SUM(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END), 0) as total_hours')
            ->selectRaw('AVG(CASE WHEN emv.column_id = 23 THEN emv.value_numeric END) as labor')
            ->first();

        $totalTips = (float) ($metrics->total_tips ?? 0);
        $totalHours = (float) ($metrics->total_hours ?? 0);

        return response()->json([
            'store' => $store,
            'date' => $day->toDateString(),
            'week_start' => $weekStartDate,
            'week_end' => $weekEndDate,

            'average_hourly_pay' => $metrics->average_hourly_pay !== null
                ? round((float) $metrics->average_hourly_pay, 2)
                : null,

            'maximum_hourly_pay' => $metrics->maximum_hourly_pay !== null
                ? round((float) $metrics->maximum_hourly_pay, 2)
                : null,

            'minimum_hourly_pay' => $metrics->minimum_hourly_pay !== null
                ? round((float) $metrics->minimum_hourly_pay, 2)
                : null,

            'total_tips' => round($totalTips, 2),
            'total_hours' => round($totalHours, 2),

            'tips_per_hour' => $totalHours > 0
                ? round($totalTips / $totalHours, 2)
                : 0,

            'labor' => $metrics->labor !== null
                ? round((float) $metrics->labor, 2)
                : null,
        ]);
    }

    public function highHoursEmployees(string $store, string $date): JsonResponse
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $weekStartDate = $weekStart->toDateString();
        $weekEndDate = $weekEnd->toDateString();

        $latestEmployeeStores = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $weekEndDate)
            ->groupBy('employee_id');

        $employees = EmployeeMetric::query()
            ->from('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->joinSub($latestEmployeeStores, 'latest_es', function ($join) {
                $join->on('latest_es.employee_id', '=', 'em.employee_id');
            })
            ->join('employee_stores as es', function ($join) {
                $join->on('es.employee_id', '=', 'latest_es.employee_id')
                    ->on('es.effective_date', '=', 'latest_es.effective_date');
            })
            ->join('stores as s', 's.id', '=', 'es.store_id')
            ->where('s.store_number', $store)
            ->whereBetween('em.metric_date', [$weekStartDate, $weekEndDate])
            ->whereIn('emv.column_id', [2, 3, 10])
            ->select('em.employee_id')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END) as column_3_value')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 2 THEN emv.value_numeric END) as column_2_value')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 10 THEN emv.value_numeric END) as column_10_value')
            ->groupBy('em.employee_id')
            ->havingRaw('MAX(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END) >= 60')
            ->get();

        return response()->json([
            'store' => $store,
            'date' => $day->toDateString(),
            'week_start' => $weekStartDate,
            'week_end' => $weekEndDate,
            'employees' => $employees->map(fn($row) => [
                'employee_id' => $row->employee_id,
                'total_hours' => $row->column_3_value !== null ? round((float) $row->column_3_value, 2) : null,
                'hourly_pay' => $row->column_2_value !== null ? round((float) $row->column_2_value, 2) : null,
                'gross_pay' => $row->column_10_value !== null ? round((float) $row->column_10_value, 2) : null,
            ])->values(),
        ]);
    }

    private function isoBusinessWeek(CarbonImmutable $date): array
    {
        $start = $date->startOfWeek(CarbonInterface::TUESDAY);
        return [$start, $start->addDays(6)];
    }
}
