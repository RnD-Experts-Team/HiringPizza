<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\SeparationType;
use App\Models\Employee;
use App\Models\EmployeeMetricColumn;
use App\Models\EmployeePayHistory;
use App\Models\EmployeePosition;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStore;
use App\Models\SeparationRequest;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LaborReportService
{
    private const DEFAULT_TREND_WEEKS = 6;

    private const MAX_TREND_WEEKS = 12;

    private const IMPACT_LOOKBACK_DAYS = 28;

    private const HIGHLIGHT_COLUMN_KEYS = [
        'total_hours', 'hourly_pay', 'gross_pay', 'labor', 'sales', 'total_tips',
        'performance_score', 'final_score',
    ];

    private const IMPACT_COLUMN_KEYS = ['total_hours', 'hourly_pay', 'performance_score'];

    private const OVERTIME_HOURS_THRESHOLD = 40;

    private const HIGH_HOURS_THRESHOLD = 60;

    private Collection $columnsById;

    private Collection $columnsByKey;

    public function getLaborReport(Store $store, string $date, ?int $trendWeeks = null): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $trendWeeks = max(1, min(self::MAX_TREND_WEEKS, $trendWeeks ?? self::DEFAULT_TREND_WEEKS));

        $this->loadColumns();

        $headcount = $this->buildHeadcount($store, $weekStart, $weekEnd);
        $tenure = $this->buildTenure($store, $weekEnd);
        $turnover = $this->buildTurnover($store, $weekStart, $weekEnd);
        $labor = $this->buildLaborColumns($store, $weekStart, $weekEnd);
        $trend = $this->buildTrend($store, $day, $trendWeeks);
        $employees = $this->buildRoster($store, $weekStart, $weekEnd);
        $overtime = $this->buildOvertime($employees);
        $summary = $this->buildSummary($headcount, $turnover, $trend, $overtime);

        return [
            'store' => $store->store_number,
            'date' => $day->toDateString(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'summary' => $summary,
            'headcount' => $headcount,
            'tenure' => $tenure,
            'turnover' => $turnover,
            'labor' => $labor,
            'overtime' => $overtime,
            'trend' => $trend,
            'employees' => $employees,
        ];
    }

    // -------------------------------------------------------------------------
    // Overtime / high-hours flags
    // -------------------------------------------------------------------------

    /**
     * Who worked overtime (>40h) or notably long weeks (>=60h) this week —
     * derived from the roster's already-computed total_hours, not a fresh query.
     */
    private function buildOvertime(array $employees): array
    {
        $withHours = collect($employees)
            ->map(fn (array $row) => [
                'employee_id' => $row['employee_id'],
                'name' => $row['name'],
                'position' => $row['position'],
                'total_hours' => $row['metrics']['total_hours']['sum'] ?? null,
            ])
            ->filter(fn (array $row) => $row['total_hours'] !== null);

        $over40 = $withHours->filter(fn (array $row) => $row['total_hours'] > self::OVERTIME_HOURS_THRESHOLD)
            ->map(fn (array $row) => $row + ['overtime_hours' => round($row['total_hours'] - self::OVERTIME_HOURS_THRESHOLD, 2)])
            ->sortByDesc('total_hours')
            ->values();

        $over60 = $withHours->filter(fn (array $row) => $row['total_hours'] >= self::HIGH_HOURS_THRESHOLD)
            ->sortByDesc('total_hours')
            ->values();

        return [
            'over_40_hours_threshold' => self::OVERTIME_HOURS_THRESHOLD,
            'over_40_hours_count' => $over40->count(),
            'over_40_hours' => $over40->all(),
            'over_60_hours_threshold' => self::HIGH_HOURS_THRESHOLD,
            'over_60_hours_count' => $over60->count(),
            'over_60_hours' => $over60->all(),
        ];
    }

    // -------------------------------------------------------------------------
    // Summary
    // -------------------------------------------------------------------------

    private function buildSummary(array $headcount, array $turnover, array $trend, array $overtime): array
    {
        $notable = collect($turnover['events'])->filter(function (array $event) {
            $impact = $event['impact_snapshot'];

            return ($impact['above_average_hours'] ?? false) || ($impact['above_average_performance'] ?? false);
        })->count();

        return [
            'headcount_current' => $headcount['active_end_of_week'],
            'new_hires_this_week' => $headcount['new_hires'],
            'separations_this_week' => $headcount['separations'],
            'notable_departures_this_week' => $notable,
            'turnover_rate_this_week_percent' => $turnover['turnover_rate_percent'],
            'avg_weekly_turnover_rate_trailing_percent' => $trend['averages']['turnover_rate_percent'] ?? null,
            'avg_weekly_gross_pay_trailing' => $trend['averages']['total_gross_pay'] ?? null,
            'avg_weekly_hours_trailing' => $trend['averages']['total_hours'] ?? null,
            'employees_over_40_hours' => $overtime['over_40_hours_count'],
            'employees_over_60_hours' => $overtime['over_60_hours_count'],
        ];
    }

    // -------------------------------------------------------------------------
    // Headcount
    // -------------------------------------------------------------------------

    private function buildHeadcount(Store $store, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $activeStartIds = $this->activeEmployeeIdsAsOf($store, $weekStart->subDay());
        $activeEndIds = $this->activeEmployeeIdsAsOf($store, $weekEnd);

        $newHires = $this->countStatusEvents($store, [EmployeeStatus::Hired, EmployeeStatus::Rehired], $weekStart, $weekEnd);
        $separations = $this->countStatusEvents($store, [EmployeeStatus::Resigned, EmployeeStatus::Terminated], $weekStart, $weekEnd);

        $positions = $this->latestPositionsAsOf($activeEndIds, $weekEnd);
        $byPosition = $positions->countBy()
            ->sortDesc()
            ->map(fn (int $count, string $position) => ['position' => $position, 'count' => $count])
            ->values()
            ->all();

        return [
            'active_start_of_week' => $activeStartIds->count(),
            'active_end_of_week' => $activeEndIds->count(),
            'new_hires' => $newHires,
            'separations' => $separations,
            'net_change' => $newHires - $separations,
            'by_position' => $byPosition,
        ];
    }

    private function countStatusEvents(Store $store, array $statuses, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): int
    {
        return EmployeeStatusHistory::query()
            ->where('store_id', $store->id)
            ->whereIn('status', array_map(fn (EmployeeStatus $s) => $s->value, $statuses))
            ->whereBetween('effective_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->count();
    }

    // -------------------------------------------------------------------------
    // Tenure
    // -------------------------------------------------------------------------

    private function buildTenure(Store $store, CarbonImmutable $weekEnd): array
    {
        $activeIds = $this->activeEmployeeIdsAsOf($store, $weekEnd);

        if ($activeIds->isEmpty()) {
            return [
                'average_tenure_days' => null,
                'distribution' => $this->tenureDistribution(collect()),
                'newest_hires' => [],
                'longest_tenured' => [],
            ];
        }

        $stintStarts = $this->currentStintStarts($activeIds, $weekEnd);

        $employees = Employee::query()
            ->whereIn('id', $activeIds)
            ->get(['id', 'first_name', 'middle_name', 'last_name']);

        $rows = $employees->map(function (Employee $employee) use ($stintStarts, $weekEnd) {
            $start = $stintStarts->get($employee->id);

            return [
                'employee_id' => $employee->id,
                'name' => $this->fullName($employee),
                'hire_date' => $start?->toDateString(),
                'tenure_days' => $start !== null ? $weekEnd->diffInDays($start) : null,
            ];
        })->filter(fn (array $row) => $row['tenure_days'] !== null)->values();

        return [
            'average_tenure_days' => $rows->isNotEmpty() ? round($rows->avg('tenure_days'), 1) : null,
            'distribution' => $this->tenureDistribution($rows),
            'newest_hires' => $rows->sortBy('tenure_days')->take(5)->values()->all(),
            'longest_tenured' => $rows->sortByDesc('tenure_days')->take(5)->values()->all(),
        ];
    }

    private function tenureDistribution(Collection $rows): array
    {
        $buckets = [
            '0-30 days' => fn (int $d) => $d <= 30,
            '31-90 days' => fn (int $d) => $d > 30 && $d <= 90,
            '91-180 days' => fn (int $d) => $d > 90 && $d <= 180,
            '181-365 days' => fn (int $d) => $d > 180 && $d <= 365,
            '1-2 years' => fn (int $d) => $d > 365 && $d <= 730,
            '2+ years' => fn (int $d) => $d > 730,
        ];

        return collect($buckets)
            ->map(fn (callable $check, string $label) => [
                'bucket' => $label,
                'count' => $rows->filter(fn (array $row) => $check($row['tenure_days']))->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Most recent hired/rehired status-history effective_date on/before $asOf,
     * per employee — i.e. the start of each employee's *current* stint.
     */
    private function currentStintStarts(Collection $employeeIds, CarbonInterface $asOf): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $rows = EmployeeStatusHistory::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', [EmployeeStatus::Hired->value, EmployeeStatus::Rehired->value])
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['employee_id', 'effective_date']);

        return $rows->unique('employee_id')->pluck('effective_date', 'employee_id');
    }

    // -------------------------------------------------------------------------
    // Turnover
    // -------------------------------------------------------------------------

    private function buildTurnover(Store $store, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $events = EmployeeStatusHistory::query()
            ->with('employee')
            ->where('store_id', $store->id)
            ->whereIn('status', [EmployeeStatus::Resigned->value, EmployeeStatus::Terminated->value])
            ->whereBetween('effective_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('effective_date')
            ->get();

        $activeStart = $this->activeEmployeeIdsAsOf($store, $weekStart->subDay())->count();
        $activeEnd = $this->activeEmployeeIdsAsOf($store, $weekEnd)->count();
        $avgHeadcount = ($activeStart + $activeEnd) / 2;

        $mapped = $events->map(fn (EmployeeStatusHistory $event) => $this->mapTurnoverEvent($event, $store));

        $voluntary = $mapped->where('type', 'voluntary')->count();
        $involuntary = $mapped->where('type', 'involuntary')->count();

        $byReason = $mapped
            ->groupBy(fn (array $e) => ($e['reason'] ?? 'unknown') . '|' . $e['type'])
            ->map(fn (Collection $group) => [
                'reason' => $group->first()['reason'],
                'type' => $group->first()['type'],
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        $count = $mapped->count();
        $turnoverRate = $avgHeadcount > 0
            ? round(($count / $avgHeadcount) * 100, 2)
            : ($count > 0 ? 100.0 : 0.0);

        return [
            'separations_count' => $count,
            'voluntary_count' => $voluntary,
            'involuntary_count' => $involuntary,
            'turnover_rate_percent' => $turnoverRate,
            'by_reason' => $byReason,
            'events' => $mapped->values()->all(),
        ];
    }

    private function mapTurnoverEvent(EmployeeStatusHistory $event, Store $store): array
    {
        $type = $event->status === EmployeeStatus::Resigned ? 'voluntary' : 'involuntary';
        $separationDate = CarbonImmutable::parse($event->effective_date);

        $separationType = $type === 'voluntary' ? SeparationType::Resignation : SeparationType::Termination;

        $bestMatch = SeparationRequest::query()
            ->where('employee_id', $event->employee_id)
            ->where('store_id', $store->id)
            ->where('separation_type', $separationType->value)
            ->whereNotNull('final_working_day')
            ->select('separation_requests.*')
            ->selectRaw('ABS(DATEDIFF(final_working_day, ?)) as day_diff', [$separationDate->toDateString()])
            ->orderBy('day_diff')
            ->first();

        $reason = null;
        if ($bestMatch !== null) {
            $reason = $type === 'voluntary'
                ? $bestMatch->resignation_reason?->value
                : $bestMatch->termination_reason?->value;
        }

        $stintStart = $this->currentStintStarts(collect([$event->employee_id]), $separationDate)->get($event->employee_id);

        return [
            'employee_id' => $event->employee_id,
            'name' => $event->employee !== null ? $this->fullName($event->employee) : null,
            'type' => $type,
            'effective_date' => $separationDate->toDateString(),
            'reason' => $reason,
            'reason_matched' => $reason !== null,
            'tenure_days' => $stintStart !== null ? $separationDate->diffInDays($stintStart) : null,
            'impact_snapshot' => $this->buildImpactSnapshot($event->employee_id, $separationDate, $stintStart, $store),
        ];
    }

    /**
     * "Did we lose a good one?" — the departing employee's own trailing metrics
     * vs. the store's average for the same window, so it's a transparent
     * comparison rather than an opaque composite score.
     */
    private function buildImpactSnapshot(
        int $employeeId,
        CarbonImmutable $separationDate,
        ?CarbonInterface $stintStart,
        Store $store
    ): array {
        $availableDays = $stintStart !== null ? $separationDate->diffInDays($stintStart) : self::IMPACT_LOOKBACK_DAYS;
        $lookbackDays = max(1, min(self::IMPACT_LOOKBACK_DAYS, $availableDays));

        $windowStart = $separationDate->subDays($lookbackDays)->toDateString();
        $windowEnd = $separationDate->toDateString();
        $weeksInWindow = $lookbackDays / 7;

        $columnIds = $this->resolveColumnIds(self::IMPACT_COLUMN_KEYS);

        $employeeStats = $this->aggregateMetrics($employeeId, null, $windowStart, $windowEnd, $columnIds);
        $storeStats = $this->aggregateMetrics(null, $store->id, $windowStart, $windowEnd, $columnIds);
        $storeActiveCount = max($this->activeEmployeeIdsAsOf($store, CarbonImmutable::parse($windowEnd))->count(), 1);

        $employeeAvgWeeklyHours = isset($employeeStats['total_hours']['sum']) && $employeeStats['total_hours']['sum'] !== null
            ? round($employeeStats['total_hours']['sum'] / $weeksInWindow, 2)
            : null;

        $storeAvgWeeklyHours = isset($storeStats['total_hours']['sum']) && $storeStats['total_hours']['sum'] !== null
            ? round(($storeStats['total_hours']['sum'] / $storeActiveCount) / $weeksInWindow, 2)
            : null;

        $employeeAvgPerformance = $employeeStats['performance_score']['avg'] ?? null;
        $storeAvgPerformance = $storeStats['performance_score']['avg'] ?? null;

        return [
            'lookback_days' => $lookbackDays,
            'avg_weekly_hours' => $employeeAvgWeeklyHours,
            'avg_hourly_pay' => $employeeStats['hourly_pay']['avg'] ?? null,
            'avg_performance_score' => $employeeAvgPerformance,
            'store_avg_weekly_hours_same_period' => $storeAvgWeeklyHours,
            'store_avg_performance_score_same_period' => $storeAvgPerformance,
            'above_average_hours' => ($employeeAvgWeeklyHours !== null && $storeAvgWeeklyHours !== null)
                ? $employeeAvgWeeklyHours > $storeAvgWeeklyHours
                : null,
            'above_average_performance' => ($employeeAvgPerformance !== null && $storeAvgPerformance !== null)
                ? $employeeAvgPerformance > $storeAvgPerformance
                : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Labor (generic per-column aggregation)
    // -------------------------------------------------------------------------

    private function buildLaborColumns(Store $store, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $columnIds = $this->columnsById->keys()->all();
        $metrics = $this->aggregateMetrics(null, $store->id, $weekStart->toDateString(), $weekEnd->toDateString(), $columnIds);

        $byColumn = collect($metrics)->filter(fn (array $m) => $m['count'] > 0)->all();

        $activeCount = $this->activeEmployeeIdsAsOf($store, $weekEnd)->count();

        return [
            'highlights' => $this->buildHighlights($metrics, $activeCount),
            'by_column' => $byColumn,
        ];
    }

    private function buildHighlights(array $metrics, int $activeCount): array
    {
        $get = fn (string $key, string $stat) => $metrics[$key][$stat] ?? null;

        $totalGrossPay = $get('gross_pay', 'sum');
        $labor = $get('labor', 'avg');

        return [
            'total_hours' => $get('total_hours', 'sum'),
            'average_hourly_pay' => $get('hourly_pay', 'avg') !== null ? round($get('hourly_pay', 'avg'), 2) : null,
            'total_gross_pay' => $totalGrossPay !== null ? round($totalGrossPay, 2) : null,
            'average_gross_pay_per_employee' => ($totalGrossPay !== null && $activeCount > 0)
                ? round($totalGrossPay / $activeCount, 2)
                : null,
            'average_labor_percent' => $labor !== null ? round($labor * 100, 2) : null,
            'total_sales' => $get('sales', 'sum') !== null ? round($get('sales', 'sum'), 2) : null,
            'total_tips' => $get('total_tips', 'sum') !== null ? round($get('total_tips', 'sum'), 2) : null,
            'average_performance_score' => $get('performance_score', 'avg') !== null ? round($get('performance_score', 'avg'), 2) : null,
            'average_final_score' => $get('final_score', 'avg') !== null ? round($get('final_score', 'avg'), 2) : null,
        ];
    }

    /**
     * Generic SUM/AVG/MIN/MAX/COUNT over employee_metric_values, resolved by
     * column key/id rather than hardcoded IDs, so any metric column added
     * later via CSV import shows up automatically.
     *
     * Scope to a single employee (roster/impact rows) via $employeeId, or to
     * every employee currently assigned to a store (as of $to) via $storeId.
     *
     * @return array<string, array{label: string, sum: ?float, avg: ?float, min: ?float, max: ?float, count: int}>
     */
    private function aggregateMetrics(?int $employeeId, ?int $storeId, string $from, string $to, array $columnIds): array
    {
        if (empty($columnIds)) {
            return [];
        }

        $query = DB::table('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->whereBetween('em.metric_date', [$from, $to])
            ->whereIn('emv.column_id', $columnIds);

        if ($employeeId !== null) {
            $query->where('em.employee_id', $employeeId);
        }

        if ($storeId !== null) {
            $latestStore = EmployeeStore::query()
                ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
                ->whereDate('effective_date', '<=', $to)
                ->groupBy('employee_id');

            $query->joinSub($latestStore, 'latest_es', fn ($join) => $join->on('latest_es.employee_id', '=', 'em.employee_id'))
                ->join('employee_stores as es', function ($join) {
                    $join->on('es.employee_id', '=', 'latest_es.employee_id')
                        ->on('es.effective_date', '=', 'latest_es.effective_date');
                })
                ->where('es.store_id', $storeId);
        }

        $rows = $query
            ->select('emv.column_id')
            ->selectRaw('SUM(emv.value_numeric) as sum_value')
            ->selectRaw('AVG(emv.value_numeric) as avg_value')
            ->selectRaw('MIN(emv.value_numeric) as min_value')
            ->selectRaw('MAX(emv.value_numeric) as max_value')
            ->selectRaw('COUNT(emv.value_numeric) as count_value')
            ->groupBy('emv.column_id')
            ->get()
            ->keyBy('column_id');

        $result = [];
        foreach ($columnIds as $columnId) {
            $column = $this->columnsById->get($columnId);

            if ($column === null) {
                continue;
            }

            $row = $rows->get($columnId);

            $result[$column->key] = [
                'label' => $column->label,
                'sum' => $row?->sum_value !== null ? round((float) $row->sum_value, 4) : null,
                'avg' => $row?->avg_value !== null ? round((float) $row->avg_value, 4) : null,
                'min' => $row?->min_value !== null ? round((float) $row->min_value, 4) : null,
                'max' => $row?->max_value !== null ? round((float) $row->max_value, 4) : null,
                'count' => $row !== null ? (int) $row->count_value : 0,
            ];
        }

        return $result;
    }

    private function resolveColumnIds(array $keys): array
    {
        return $this->columnsByKey->only($keys)->pluck('id')->all();
    }

    // -------------------------------------------------------------------------
    // Trend (trailing weeks)
    // -------------------------------------------------------------------------

    private function buildTrend(Store $store, CarbonImmutable $day, int $trendWeeks): array
    {
        $entries = collect();

        for ($i = $trendWeeks - 1; $i >= 0; $i--) {
            [$weekStart, $weekEnd] = $this->isoBusinessWeek($day->subWeeks($i));
            $entries->push($this->buildTrendWeek($store, $weekStart, $weekEnd));
        }

        $averages = $this->averageTrendEntries($entries);
        $thisWeek = $entries->last();

        $comparison = collect(['total_gross_pay', 'total_hours', 'turnover_rate_percent'])
            ->map(function (string $key) use ($thisWeek, $averages) {
                $current = $thisWeek[$key] ?? null;
                $average = $averages[$key] ?? null;

                $delta = ($current !== null && $average !== null && (float) $average !== 0.0)
                    ? round((($current - $average) / abs($average)) * 100, 2)
                    : null;

                return [
                    'metric' => $key,
                    'this_week' => $current,
                    'trailing_average' => $average,
                    'delta_percent' => $delta,
                ];
            })
            ->values()
            ->all();

        return [
            'weeks' => $entries->all(),
            'averages' => $averages,
            'comparison_to_average' => $comparison,
        ];
    }

    private function buildTrendWeek(Store $store, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $activeStart = $this->activeEmployeeIdsAsOf($store, $weekStart->subDay())->count();
        $activeEnd = $this->activeEmployeeIdsAsOf($store, $weekEnd)->count();
        $avgHeadcount = ($activeStart + $activeEnd) / 2;

        $newHires = $this->countStatusEvents($store, [EmployeeStatus::Hired, EmployeeStatus::Rehired], $weekStart, $weekEnd);
        $separations = $this->countStatusEvents($store, [EmployeeStatus::Resigned, EmployeeStatus::Terminated], $weekStart, $weekEnd);

        $turnoverRate = $avgHeadcount > 0
            ? round(($separations / $avgHeadcount) * 100, 2)
            : ($separations > 0 ? 100.0 : 0.0);

        $columnIds = $this->resolveColumnIds(self::HIGHLIGHT_COLUMN_KEYS);
        $metrics = $this->aggregateMetrics(null, $store->id, $weekStart->toDateString(), $weekEnd->toDateString(), $columnIds);

        $labor = $metrics['labor']['avg'] ?? null;

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'headcount_active_end' => $activeEnd,
            'new_hires' => $newHires,
            'separations' => $separations,
            'turnover_rate_percent' => $turnoverRate,
            'total_hours' => $metrics['total_hours']['sum'] ?? null,
            'average_hourly_pay' => $metrics['hourly_pay']['avg'] ?? null,
            'total_gross_pay' => $metrics['gross_pay']['sum'] ?? null,
            'average_labor_percent' => $labor !== null ? round($labor * 100, 2) : null,
            'total_sales' => $metrics['sales']['sum'] ?? null,
            'average_performance_score' => $metrics['performance_score']['avg'] ?? null,
        ];
    }

    private function averageTrendEntries(Collection $entries): array
    {
        $keys = [
            'headcount_active_end', 'new_hires', 'separations', 'turnover_rate_percent',
            'total_hours', 'average_hourly_pay', 'total_gross_pay', 'average_labor_percent',
            'total_sales', 'average_performance_score',
        ];

        $result = [];
        foreach ($keys as $key) {
            $values = $entries->pluck($key)->filter(fn ($v) => $v !== null);
            $result[$key] = $values->isNotEmpty() ? round($values->avg(), 2) : null;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Roster
    // -------------------------------------------------------------------------

    private function buildRoster(Store $store, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $activeIds = $this->activeEmployeeIdsAsOf($store, $weekEnd);
        $metricIds = $this->employeeIdsWithMetricActivity($store, $weekStart->toDateString(), $weekEnd->toDateString());

        $employeeIds = $activeIds->merge($metricIds)->unique()->values();

        if ($employeeIds->isEmpty()) {
            return [];
        }

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name']);

        $stintStarts = $this->currentStintStarts($employeeIds, $weekEnd);
        $statuses = $this->latestStatusesAsOf($employeeIds, $weekEnd);
        $positions = $this->latestPositionsAsOf($employeeIds, $weekEnd);
        $pay = $this->latestPayAsOf($employeeIds, $weekEnd);
        $columnIds = $this->columnsById->keys()->all();

        return $employees->map(function (Employee $employee) use (
            $stintStarts,
            $statuses,
            $positions,
            $pay,
            $columnIds,
            $weekStart,
            $weekEnd
        ) {
            $stintStart = $stintStarts->get($employee->id);
            $status = $statuses->get($employee->id);
            $payRow = $pay->get($employee->id);

            $metrics = $this->aggregateMetrics($employee->id, null, $weekStart->toDateString(), $weekEnd->toDateString(), $columnIds);
            $sparse = collect($metrics)
                ->filter(fn (array $m) => $m['count'] > 0)
                ->map(fn (array $m) => ['label' => $m['label'], 'sum' => $m['sum'], 'avg' => $m['avg']])
                ->all();

            return [
                'employee_id' => $employee->id,
                'name' => $this->fullName($employee),
                'position' => $positions->get($employee->id),
                'status' => in_array($status, [EmployeeStatus::Resigned->value, EmployeeStatus::Terminated->value], true)
                    ? 'inactive'
                    : 'active',
                'hire_date' => $stintStart?->toDateString(),
                'tenure_days' => $stintStart !== null ? $weekEnd->diffInDays($stintStart) : null,
                'base_pay' => $payRow?->base_pay,
                'performance_pay' => $payRow?->performance_pay,
                'metrics' => $sparse,
            ];
        })->values()->all();
    }

    private function employeeIdsWithMetricActivity(Store $store, string $from, string $to): Collection
    {
        $latestStore = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $to)
            ->groupBy('employee_id');

        return DB::table('employee_metrics as em')
            ->joinSub($latestStore, 'latest_es', fn ($join) => $join->on('latest_es.employee_id', '=', 'em.employee_id'))
            ->join('employee_stores as es', function ($join) {
                $join->on('es.employee_id', '=', 'latest_es.employee_id')
                    ->on('es.effective_date', '=', 'latest_es.effective_date');
            })
            ->where('es.store_id', $store->id)
            ->whereBetween('em.metric_date', [$from, $to])
            ->distinct()
            ->pluck('em.employee_id');
    }

    // -------------------------------------------------------------------------
    // Shared "as of a date" lookups
    // -------------------------------------------------------------------------

    private function storeAssignedEmployeeIdsAsOf(Store $store, CarbonInterface $asOfDate): Collection
    {
        $asOf = $asOfDate->toDateString();

        $latestStore = EmployeeStore::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereDate('effective_date', '<=', $asOf)
            ->groupBy('employee_id');

        return DB::table('employee_stores as es')
            ->joinSub($latestStore, 'latest_es', function ($join) {
                $join->on('latest_es.employee_id', '=', 'es.employee_id')
                    ->on('latest_es.effective_date', '=', 'es.effective_date');
            })
            ->where('es.store_id', $store->id)
            ->distinct()
            ->pluck('es.employee_id');
    }

    private function activeEmployeeIdsAsOf(Store $store, CarbonInterface $asOfDate): Collection
    {
        $assigned = $this->storeAssignedEmployeeIdsAsOf($store, $asOfDate);

        if ($assigned->isEmpty()) {
            return $assigned;
        }

        $statuses = $this->latestStatusesAsOf($assigned, $asOfDate);

        return $assigned->filter(function (int $employeeId) use ($statuses) {
            $status = $statuses->get($employeeId);

            return $status === null
                || !in_array($status, [EmployeeStatus::Resigned->value, EmployeeStatus::Terminated->value], true);
        })->values();
    }

    private function latestStatusesAsOf(Collection $employeeIds, CarbonInterface $asOf): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $rows = EmployeeStatusHistory::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['employee_id', 'status']);

        return $rows->unique('employee_id')->pluck('status', 'employee_id')->map(fn ($status) => $status->value);
    }

    private function latestPositionsAsOf(Collection $employeeIds, CarbonInterface $asOf): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $latest = EmployeePosition::query()
            ->select('employee_id', DB::raw('MAX(effective_date) as effective_date'))
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->groupBy('employee_id');

        return DB::table('employee_positions as ep')
            ->joinSub($latest, 'latest_ep', function ($join) {
                $join->on('latest_ep.employee_id', '=', 'ep.employee_id')
                    ->on('latest_ep.effective_date', '=', 'ep.effective_date');
            })
            ->join('positions as p', 'p.id', '=', 'ep.position_id')
            ->select('ep.employee_id', 'p.label')
            ->get()
            ->unique('employee_id')
            ->pluck('label', 'employee_id');
    }

    private function latestPayAsOf(Collection $employeeIds, CarbonInterface $asOf): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $rows = EmployeePayHistory::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['employee_id', 'base_pay', 'performance_pay']);

        return $rows->unique('employee_id')->keyBy('employee_id');
    }

    // -------------------------------------------------------------------------
    // Small utilities
    // -------------------------------------------------------------------------

    private function loadColumns(): void
    {
        $columns = EmployeeMetricColumn::query()->get();

        $this->columnsById = $columns->keyBy('id');
        $this->columnsByKey = $columns->keyBy('key');
    }

    private function isoBusinessWeek(CarbonImmutable $date): array
    {
        $start = $date->startOfWeek(CarbonInterface::TUESDAY);

        return [$start, $start->addDays(6)];
    }

    private function fullName(Employee $employee): string
    {
        return trim(implode(' ', array_filter([
            $employee->first_name,
            $employee->middle_name,
            $employee->last_name,
        ])));
    }
}
