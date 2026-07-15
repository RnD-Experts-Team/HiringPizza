<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeMetric;
use App\Models\EmployeeStore;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
class ManagerDashboardService
{

    // -------------------------------------------------------------------------
    // Multi-store, date-range API
    // -------------------------------------------------------------------------

    /**
     * Returns the same four dashboard sections as reports() but for multiple
     * stores over an explicit date range, keyed by store number.
     *
     * @param  string[] $stores  Store numbers, or ['all'] / ['all', ...] to include every store.
     */
    public function getMultiStoreReports(array $stores, string $startDate, string $endDate): array
    {
        $storeNumbers = in_array('all', $stores, true)
            ? Store::pluck('store_number')->all()
            : $stores;

        $dashboard   = $this->bulkDashboard($storeNumbers, $startDate, $endDate);
        $highHours   = $this->bulkHighHoursEmployees($storeNumbers, $startDate, $endDate);
        $avgPay      = $this->bulkAverageHourlyPayDetailed($storeNumbers, $startDate, $endDate);
        $weeklyLabor = $this->bulkWeeklyLabor($storeNumbers, $startDate, $endDate);
        $totalHours  = $this->bulkTotalHours($storeNumbers, $startDate, $endDate);

        $result = [];
        foreach ($storeNumbers as $storeNumber) {
            $result[$storeNumber] = [
                'manager-dashboard'    => $dashboard[$storeNumber],
                'high-hours-employees' => $highHours[$storeNumber],
                'average-hourly-pay'   => $avgPay[$storeNumber],
                'weekly-labor'         => $weeklyLabor[$storeNumber],
                'total-hours'          => $totalHours[$storeNumber],
            ];
        }

        return $result;
    }

    private function bulkDashboard(array $storeNumbers, string $startDate, string $endDate): array
    {
        $endDay = CarbonImmutable::parse($endDate);

        $employees = Employee::query()
            ->whereHas('stores', function ($q) use ($storeNumbers) {
                $q->whereHas('store', fn ($sq) => $sq->whereIn('store_number', $storeNumbers))
                    ->whereRaw('employee_stores.id = (
                        SELECT s2.id FROM employee_stores s2
                        WHERE s2.employee_id = employee_stores.employee_id
                        ORDER BY s2.effective_date DESC, s2.id DESC LIMIT 1
                    )');
            })
            ->with([
                'latestStore.store',
                'statusHistories' => function ($q) {
                    $q->orderBy('effective_date', 'desc')->orderBy('id', 'desc');
                },
                'positions',
                'payHistories',
                'obsession',
                'metrics' => function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('metric_date', [$startDate, $endDate])
                        ->join('employee_metric_values', function ($join) {
                            $join->on('employee_metric_values.employee_metric_id', '=', 'employee_metrics.id')
                                ->whereIn('employee_metric_values.column_id', [2, 3, 10, 31]);
                        })
                        ->join('employee_metric_columns', function ($join) {
                            $join->on('employee_metric_columns.id', '=', 'employee_metric_values.column_id');
                        })
                        ->select([
                            'employee_metrics.id',
                            'employee_metrics.employee_id',
                            'employee_metrics.metric_date',
                            'employee_metric_columns.label as column_label',
                            'employee_metric_values.value',
                            'employee_metric_values.value_numeric',
                        ]);
                },
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        /** @var Collection<string, Collection<int, Employee>> $grouped */
        $grouped = $employees->groupBy(fn (Employee $e) => $e->latestStore?->store?->store_number);

        $result = [];
        foreach ($storeNumbers as $storeNumber) {
            $storeEmployees = $grouped->get($storeNumber, collect())
                ->filter(fn (Employee $e) => $this->isActiveEmployee($e) || $e->metrics->isNotEmpty())
                ->values();

            $result[$storeNumber] = [
                'store_id'   => $storeNumber,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'employees'  => $storeEmployees->map(fn (Employee $e) => $this->mapEmployee($e, $endDay))->values()->all(),
            ];
        }

        return $result;
    }

    private function bulkHighHoursEmployees(array $storeNumbers, string $startDate, string $endDate): array
    {
        $latestEmployeeStores = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $endDate)
            ->groupBy('employee_id');

        $rows = EmployeeMetric::query()
            ->from('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->join('employees as e', 'e.id', '=', 'em.employee_id')
            ->joinSub($latestEmployeeStores, 'latest_es', function ($join) {
                $join->on('latest_es.employee_id', '=', 'em.employee_id');
            })
            ->join('employee_stores as es', function ($join) {
                $join->on('es.employee_id', '=', 'latest_es.employee_id')
                    ->on('es.effective_date', '=', 'latest_es.effective_date');
            })
            ->join('stores as s', 's.id', '=', 'es.store_id')
            ->whereIn('s.store_number', $storeNumbers)
            ->whereBetween('em.metric_date', [$startDate, $endDate])
            ->whereIn('emv.column_id', [1, 2, 3, 10])
            ->select('em.employee_id', 'e.first_name', 'e.last_name', 's.store_number')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 1 THEN emv.value END) as position')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END) as column_3_value')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 2 THEN emv.value_numeric END) as column_2_value')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 10 THEN emv.value_numeric END) as column_10_value')
            ->groupBy('em.employee_id', 'e.first_name', 'e.last_name', 's.store_number')
            ->havingRaw('MAX(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END) >= 60')
            ->get()
            ->groupBy('store_number');

        $result = [];
        foreach ($storeNumbers as $storeNumber) {
            $storeRows = $rows->get($storeNumber, collect());

            $result[$storeNumber] = [
                'store'      => $storeNumber,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'employees'  => $storeRows->map(fn ($row) => [
                    'employee_id' => $row->employee_id,
                    'first_name'  => $row->first_name,
                    'last_name'   => $row->last_name,
                    'position'    => $row->position,
                    'total_hours' => $row->column_3_value !== null ? round((float) $row->column_3_value, 2) : null,
                    'hourly_pay'  => $row->column_2_value !== null ? round((float) $row->column_2_value, 2) : null,
                    'gross_pay'   => $row->column_10_value !== null ? round((float) $row->column_10_value, 2) : null,
                ])->values()->all(),
            ];
        }

        return $result;
    }

    private function bulkAverageHourlyPayDetailed(array $storeNumbers, string $startDate, string $endDate): array
    {
        $latestEmployeeStores = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $endDate)
            ->groupBy('employee_id');

        $rows = EmployeeMetric::query()
            ->from('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->join('employees as e', 'e.id', '=', 'em.employee_id')
            ->joinSub($latestEmployeeStores, 'latest_es', function ($join) {
                $join->on('latest_es.employee_id', '=', 'em.employee_id');
            })
            ->join('employee_stores as es', function ($join) {
                $join->on('es.employee_id', '=', 'latest_es.employee_id')
                    ->on('es.effective_date', '=', 'latest_es.effective_date');
            })
            ->join('stores as s', 's.id', '=', 'es.store_id')
            ->whereIn('s.store_number', $storeNumbers)
            ->whereBetween('em.metric_date', [$startDate, $endDate])
            ->whereIn('emv.column_id', [2, 3, 4, 23])
            ->select('em.employee_id', 'e.first_name', 'e.last_name', 's.store_number')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 2 THEN emv.value_numeric END) as hourly_pay')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END) as total_hours')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 4 THEN emv.value_numeric END) as tips')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 23 THEN emv.value_numeric END) as labor')
            ->groupBy('em.employee_id', 'e.first_name', 'e.last_name', 's.store_number')
            ->get()
            ->groupBy('store_number');

        $result = [];
        foreach ($storeNumbers as $storeNumber) {
            $storeRows = $rows->get($storeNumber, collect());

            $result[$storeNumber] = [
                'store'      => $storeNumber,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'employees'  => $storeRows->map(fn ($row) => [
                    'employee_id' => $row->employee_id,
                    'first_name'  => $row->first_name,
                    'last_name'   => $row->last_name,
                    'hourly_pay'  => $row->hourly_pay !== null ? round((float) $row->hourly_pay, 2) : null,
                    'total_hours' => $row->total_hours !== null ? round((float) $row->total_hours, 2) : null,
                    'tips'        => $row->tips !== null ? round((float) $row->tips, 2) : null,
                    'labor'       => $row->labor !== null ? round((float) $row->labor * 100, 2) : null,
                ])->values()->all(),
            ];
        }

        return $result;
    }

    private function bulkWeeklyLabor(array $storeNumbers, string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate);
        $end   = CarbonImmutable::parse($endDate);

        // Build week buckets covering the full date range (Tue–Mon boundaries).
        $weeks    = [];
        $cursor   = $this->isoBusinessWeek($start)[0]; // first Tuesday on or before start
        $rangeEnd = $this->isoBusinessWeek($end)[1];   // last Monday on or after end

        while ($cursor->lte($rangeEnd)) {
            [$weekStart, $weekEnd] = $this->isoBusinessWeek($cursor);
            $weeks[] = ['week_start' => $weekStart->toDateString(), 'week_end' => $weekEnd->toDateString()];
            $cursor  = $cursor->addWeek();
        }

        if (empty($weeks)) {
            $result = [];
            foreach ($storeNumbers as $storeNumber) {
                $result[$storeNumber] = ['store' => $storeNumber, 'entries' => []];
            }
            return $result;
        }

        $overallStart = $weeks[0]['week_start'];
        $overallEnd   = $weeks[count($weeks) - 1]['week_end'];

        $latestEmployeeStores = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $overallEnd)
            ->groupBy('employee_id');

        $rows = EmployeeMetric::query()
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
            ->whereIn('s.store_number', $storeNumbers)
            ->whereBetween('em.metric_date', [$overallStart, $overallEnd])
            ->where('emv.column_id', 23)
            ->select([])
            ->selectRaw('s.store_number')
            ->selectRaw('FLOOR(DATEDIFF(em.metric_date, ?) / 7) AS week_index', [$overallStart])
            ->selectRaw('AVG(emv.value_numeric) AS labor')
            ->groupByRaw('s.store_number, week_index')
            ->get()
            ->groupBy('store_number');

        $result = [];
        foreach ($storeNumbers as $storeNumber) {
            $storeRows = $rows->get($storeNumber, collect())->keyBy('week_index');

            $entries = [];
            foreach ($weeks as $index => $week) {
                $entries[] = [
                    'week_start' => $week['week_start'],
                    'week_end'   => $week['week_end'],
                    'labor'      => isset($storeRows[$index]) && $storeRows[$index]->labor !== null
                        ? round((float) $storeRows[$index]->labor * 100, 2)
                        : null,
                ];
            }

            $result[$storeNumber] = ['store' => $storeNumber, 'entries' => $entries];
        }

        return $result;
    }

    private function bulkTotalHours(array $storeNumbers, string $startDate, string $endDate): array
    {
        $latestEmployeeStores = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $endDate)
            ->groupBy('employee_id');

        $rows = EmployeeMetric::query()
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
            ->whereIn('s.store_number', $storeNumbers)
            ->whereBetween('em.metric_date', [$startDate, $endDate])
            ->where('emv.column_id', 3)
            ->select('s.store_number')
            ->selectRaw('COALESCE(SUM(emv.value_numeric), 0) as total_hours')
            ->groupBy('s.store_number')
            ->get()
            ->keyBy('store_number');

        $result = [];
        foreach ($storeNumbers as $storeNumber) {
            $result[$storeNumber] = [
                'store'       => $storeNumber,
                'start_date'  => $startDate,
                'end_date'    => $endDate,
                'total_hours' => round((float) ($rows->get($storeNumber)->total_hours ?? 0), 2),
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------

    private function isoBusinessWeek(CarbonImmutable $date): array
    {
        $start = $date->startOfWeek(CarbonInterface::TUESDAY);
        return [$start, $start->addDays(6)];
    }

    public function getAverageHourlyPay(string $store, string $date): array
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

        return [
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
        ];
    }

    public function getHighHoursEmployees(string $store, string $date): array
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
            ->join('employees as e', 'e.id', '=', 'em.employee_id')
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
            ->whereIn('emv.column_id', [1, 2, 3, 10])
            ->select('em.employee_id', 'e.first_name', 'e.last_name')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 1 THEN emv.value END) as position')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END) as column_3_value')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 2 THEN emv.value_numeric END) as column_2_value')
            ->selectRaw('MAX(CASE WHEN emv.column_id = 10 THEN emv.value_numeric END) as column_10_value')
            ->groupBy('em.employee_id', 'e.first_name', 'e.last_name')
            ->havingRaw('MAX(CASE WHEN emv.column_id = 3 THEN emv.value_numeric END) >= 60')
            ->get();

        return [
            'store' => $store,
            'date' => $day->toDateString(),
            'week_start' => $weekStartDate,
            'week_end' => $weekEndDate,
            'employees' => $employees->map(fn($row) => [
                'employee_id' => $row->employee_id,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'position' => $row->position,
                'total_hours' => $row->column_3_value !== null ? round((float) $row->column_3_value, 2) : null,
                'hourly_pay' => $row->column_2_value !== null ? round((float) $row->column_2_value, 2) : null,
                'gross_pay' => $row->column_10_value !== null ? round((float) $row->column_10_value, 2) : null,
            ])->values(),
        ];
    }

    public function getWeeklyLabor(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        // Build 6 week ranges: current week and 5 preceding, oldest first
        $weeks = [];
        for ($i = 5; $i >= 0; $i--) {
            [$start, $end] = $this->isoBusinessWeek($day->subWeeks($i));
            $weeks[] = ['week_start' => $start->toDateString(), 'week_end' => $end->toDateString()];
        }

        $overallStart = $weeks[0]['week_start'];
        $overallEnd   = $weeks[5]['week_end'];

        $latestEmployeeStores = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $overallEnd)
            ->groupBy('employee_id');

        // One query over all 6 weeks; DATEDIFF from the oldest Tuesday buckets each
        // row into its week index (0 = oldest, 5 = current) since each week is exactly 7 days.
        $rows = EmployeeMetric::query()
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
            ->whereBetween('em.metric_date', [$overallStart, $overallEnd])
            ->where('emv.column_id', 23)
            ->select([])
            ->selectRaw('FLOOR(DATEDIFF(em.metric_date, ?) / 7) AS week_index', [$overallStart])
            ->selectRaw('AVG(emv.value_numeric) AS labor')
            ->groupByRaw('week_index')
            ->get()
            ->keyBy('week_index');

        $entries = [];
        foreach ($weeks as $index => $week) {
            $entries[] = [
                'week_start' => $week['week_start'],
                'week_end'   => $week['week_end'],
                'labor'      => isset($rows[$index]) && $rows[$index]->labor !== null
                    ? round((float) $rows[$index]->labor * 100, 2)
                    : null,
            ];
        }

        return [
            'store'   => $store,
            'entries' => $entries,
        ];
    }

    public function getTotalHours(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $weekStartDate = $weekStart->toDateString();
        $weekEndDate = $weekEnd->toDateString();

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
            ->where('emv.column_id', 3)
            ->selectRaw('COALESCE(SUM(emv.value_numeric), 0) as total_hours')
            ->first();

        return [
            'store' => $store,
            'date' => $day->toDateString(),
            'week_start' => $weekStartDate,
            'week_end' => $weekEndDate,
            'total_hours' => round((float) ($metrics->total_hours ?? 0), 2),
        ];
    }

    public function getDashboard(Store $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $employees = Employee::query()
            ->whereHas('stores', function ($q) use ($store) {
                $q->where('store_id', $store->id)
                    ->whereRaw('employee_stores.id = (
                      SELECT s2.id FROM employee_stores s2
                      WHERE s2.employee_id = employee_stores.employee_id
                      ORDER BY s2.effective_date DESC, s2.id DESC LIMIT 1
                  )');
            })
            ->with([
                'statusHistories' => function ($q) {
                    $q->orderBy('effective_date', 'desc')->orderBy('id', 'desc');
                },
                'positions',
                'payHistories',
                'obsession',
                'metrics' => function ($q) use ($weekStart, $weekEnd) {
                    $previousWeekStart = $weekStart->subWeek();
                    $previousWeekEnd = $weekEnd->subWeek();

                    $q->whereBetween('metric_date', [
                        $previousWeekStart->toDateString(),
                        $previousWeekEnd->toDateString(),
                    ])
                        ->join('employee_metric_values', function ($join) {
                            $join->on('employee_metric_values.employee_metric_id', '=', 'employee_metrics.id')
                                ->whereIn('employee_metric_values.column_id', [2, 3, 10, 31]);
                        })
                        ->join('employee_metric_columns', function ($join) {
                            $join->on('employee_metric_columns.id', '=', 'employee_metric_values.column_id');
                        })
                        ->select([
                            'employee_metrics.id',
                            'employee_metrics.employee_id',
                            'employee_metrics.metric_date',
                            'employee_metric_columns.label as column_label',
                            'employee_metric_values.value',
                            'employee_metric_values.value_numeric',
                        ]);
                },
                'currentWeekMetrics' => function ($q) use ($weekStart, $weekEnd) {
                    $q->whereBetween('metric_date', [
                        $weekStart->toDateString(),
                        $weekEnd->toDateString(),
                    ])
                        ->join('employee_metric_values', function ($join) {
                            $join->on('employee_metric_values.employee_metric_id', '=', 'employee_metrics.id')
                                ->whereIn('employee_metric_values.column_id', [2, 3, 10, 31]);
                        })
                        ->join('employee_metric_columns', function ($join) {
                            $join->on('employee_metric_columns.id', '=', 'employee_metric_values.column_id');
                        })
                        ->select([
                            'employee_metrics.id',
                            'employee_metrics.employee_id',
                            'employee_metrics.metric_date',
                            'employee_metric_columns.label as column_label',
                            'employee_metric_values.value',
                            'employee_metric_values.value_numeric',
                        ]);
                },
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->filter(fn(Employee $employee) => $this->isActiveEmployee($employee)
                || $employee->metrics->isNotEmpty()
                || $employee->currentWeekMetrics->isNotEmpty())
            ->values();

        return [
            'store_id' => $store->store_number,
            'date' => $day->toDateString(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'previous_week_start' => $weekStart->subWeek()->toDateString(),
            'previous_week_end' => $weekEnd->subWeek()->toDateString(),
            'employees' => $employees->map(fn(Employee $emp) => $this->mapEmployee($emp, $day))->values()->all(),
        ];
    }

    private function mapEmployee(Employee $employee, CarbonImmutable $date): array
    {
        $latestPosition = $employee->positions->first();
        $latestPay = $employee->payHistories->first();
        $metricEntry = $this->resolveMetric($employee->metrics);
        $currentMetricEntry = $this->resolveMetric($employee->currentWeekMetrics);

        return [
            'employee_id' => $employee->id,
            'name' => [
                'first' => $employee->first_name,
                'middle' => $employee->middle_name,
                'last' => $employee->last_name,
            ],
            'status' => $this->isActiveEmployee($employee) ? 'active' : 'inactive',
            'birthday' => $this->resolveBirthday($employee, $date),
            'position' => $latestPosition?->position?->label,
            'base_pay' => $latestPay ? number_format((float) $latestPay->base_pay, 2, '.', '') : null,
            'performance_pay' => $latestPay ? number_format((float) $latestPay->performance_pay, 2, '.', '') : null,
            'metrics' => $metricEntry,
            'current_metrics' => $currentMetricEntry,
        ];
    }

    private function isActiveEmployee(Employee $employee): bool
    {
        $latestStatus = $employee->statusHistories->first()?->status;

        if ($latestStatus === null) {
            return true;
        }

        return !in_array($latestStatus->value, [EmployeeStatus::Resigned->value, EmployeeStatus::Terminated->value], true);
    }

    private function resolveBirthday(Employee $employee, CarbonImmutable $date): array
    {
        $birthDate = $employee->obsession?->birth_date;

        if ($birthDate === null) {
            return ['is_upcoming' => false];
        }

        $birth = Carbon::parse($birthDate);

        // Find the next occurrence of this month/day on or after $date
        $candidate = Carbon::create($date->year, $birth->month, $birth->day)->startOfDay();
        if ($candidate->lt($date)) {
            $candidate->addYear();
        }

        $windowEnd = $date->copy()->addDays(6)->startOfDay();

        if ($candidate->gt($windowEnd)) {
            return ['is_upcoming' => false];
        }

        return [
            'is_upcoming' => true,
            'birth_date' => $birth->toDateString(),
            'days_until' => (int) $date->diffInDays($candidate),
            'turns_age' => $candidate->year - $birth->year,
        ];
    }

    private function resolveMetric(Collection $metrics): array
    {
        return $metrics
            ->sortByDesc('metric_date')
            ->map(fn($metric) => [
                'metric_date' => $metric->metric_date,
                'label' => $metric->column_label,
                'value' => $metric->value,
                'value_numeric' => $metric->value_numeric !== null
                    ? (float) $metric->value_numeric
                    : null,
            ])
            ->values()
            ->all();
    }
}
