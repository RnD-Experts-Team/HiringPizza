<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\SeparationType;
use App\Models\Employee;
use App\Models\EmployeeMetricColumn;
use App\Models\EmployeePayHistory;
use App\Models\EmployeePosition;
use App\Models\EmployeeStatusHistory;
use App\Models\SeparationRequest;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Labor report: headcount, tenure, turnover, overtime and labor metrics for
 * a set of stores over an inclusive date range.
 *
 * getLaborReport() is one store and one Tue–Mon business week (the single-store
 * page); getRangeReport() is any stores over any range (Business Reports). Both
 * run on the same set-based lookups: each one takes the list of store ids plus a
 * list of dates or date windows and answers them in one grouped query, so the
 * number of queries follows the weeks and separation dates in the range, never
 * stores × weeks.
 *
 * "Who is in a store on a date" is always the employee's latest store
 * assignment on or before that date; "active" additionally means their latest
 * status on or before that date is not resigned/terminated.
 */
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

    private const TENURE_LIST_SIZE = 5;

    /** Windows per derived table. SQLite allows 500 terms in one compound SELECT. */
    private const WINDOWS_PER_QUERY = 200;

    private Collection $columnsById;

    private Collection $columnsByKey;

    public function getLaborReport(Store $store, string $date, ?int $trendWeeks = null): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $trendWeeks = max(1, min(self::MAX_TREND_WEEKS, $trendWeeks ?? self::DEFAULT_TREND_WEEKS));

        $trailing = [];
        for ($i = $trendWeeks - 1; $i >= 0; $i--) {
            $trailing[] = $this->isoBusinessWeek($day->subWeeks($i));
        }

        $report = $this->build([$store->id => $store->store_number], $weekStart, $weekEnd, $trailing, false)['combined'];

        $headcount = $report['headcount'];
        $overtime = $report['overtime'];
        $trend = $this->trendWithAverages($report['trend']);

        return [
            'store' => $store->store_number,
            'date' => $day->toDateString(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'summary' => [
                'headcount_current' => $headcount['active_end'],
                'new_hires_this_week' => $headcount['new_hires'],
                'separations_this_week' => $headcount['separations'],
                'notable_departures_this_week' => $report['summary']['notable_departures'],
                'turnover_rate_this_week_percent' => $report['turnover']['turnover_rate_percent'],
                'avg_weekly_turnover_rate_trailing_percent' => $trend['averages']['turnover_rate_percent'] ?? null,
                'avg_weekly_gross_pay_trailing' => $trend['averages']['total_gross_pay'] ?? null,
                'avg_weekly_hours_trailing' => $trend['averages']['total_hours'] ?? null,
                'employees_over_40_hours' => $overtime['over_40_count'],
                'employees_over_60_hours' => $overtime['over_60_count'],
            ],
            'headcount' => [
                'active_start_of_week' => $headcount['active_start'],
                'active_end_of_week' => $headcount['active_end'],
                'new_hires' => $headcount['new_hires'],
                'separations' => $headcount['separations'],
                'net_change' => $headcount['net_change'],
                'by_position' => $headcount['by_position'],
            ],
            'tenure' => $report['tenure'],
            'turnover' => $report['turnover'],
            'labor' => $report['labor'],
            'overtime' => [
                'over_40_hours_threshold' => self::OVERTIME_HOURS_THRESHOLD,
                'over_40_hours_count' => $overtime['over_40_count'],
                'over_40_hours' => array_map(
                    fn (array $row) => Arr::only($row, ['employee_id', 'name', 'position', 'total_hours', 'overtime_hours']),
                    $overtime['over_40'],
                ),
                'over_60_hours_threshold' => self::HIGH_HOURS_THRESHOLD,
                'over_60_hours_count' => $overtime['over_60_count'],
                'over_60_hours' => array_map(
                    fn (array $row) => Arr::only($row, ['employee_id', 'name', 'position', 'total_hours']),
                    $overtime['over_60'],
                ),
            ],
            'trend' => $trend,
            'employees' => $report['roster'],
        ];
    }

    /**
     * Any stores over any inclusive range. `combined` is every store together
     * and carries the lists (each item names its store); `by_store` keeps the
     * counts and aggregates only. The trend, overtime and final labor follow
     * the Tue–Mon business weeks that overlap the range, whole weeks.
     *
     * @param  Collection<int, Store>  $stores
     */
    public function getRangeReport(Collection $stores, string $startDate, string $endDate): array
    {
        $from = CarbonImmutable::parse($startDate)->startOfDay();
        $to = CarbonImmutable::parse($endDate)->startOfDay();

        $weeks = [];
        for ($weekStart = $this->isoBusinessWeek($from)[0]; $weekStart->lte($to); $weekStart = $weekStart->addWeek()) {
            $weeks[] = $this->isoBusinessWeek($weekStart);
        }

        $storeNumbers = $stores->sortBy('store_number')->pluck('store_number', 'id')->all();
        $report = $this->build($storeNumbers, $from, $to, $weeks, true);

        $byStore = [];
        foreach ($report['by_store'] as $storeId => $block) {
            $byStore[$storeNumbers[$storeId]] = $block;
        }

        return [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
            'weeks' => array_map(fn (array $week) => [
                'week_start' => $week[0]->toDateString(),
                'week_end' => $week[1]->toDateString(),
            ], $weeks),
            'combined' => $report['combined'],
            'by_store' => (object) $byStore,
        ];
    }

    // -------------------------------------------------------------------------
    // Assembly
    // -------------------------------------------------------------------------

    /**
     * Runs every lookup once for all the stores, then cuts the results into the
     * combined block and (for a range report) one block per store.
     *
     * @param  array<int, string>  $stores  store id => store number
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $weeks  contiguous trend weeks
     * @return array{combined: array, by_store: array<int, array>}
     */
    private function build(array $stores, CarbonImmutable $from, CarbonImmutable $to, array $weeks, bool $range): array
    {
        $this->loadColumns();
        $storeIds = array_keys($stores);
        $allColumnIds = $this->columnsById->keys()->all();

        $events = $this->separationEvents($storeIds, $from, $to);
        $members = $this->activeMembersAsOf($storeIds, $to);

        $dates = [$from->subDay(), $to];
        foreach ($weeks as [$weekStart, $weekEnd]) {
            $dates[] = $weekStart->subDay();
            $dates[] = $weekEnd;
        }
        foreach ($events as $event) {
            $dates[] = $event->effective_date;
        }
        $active = $this->activeCountsAsOf(
            $storeIds,
            collect($dates)->map(fn (CarbonInterface $date) => $date->toDateString())->unique()->values()->all(),
        );
        $activeAt = fn (CarbonInterface $date, ?int $storeId): int => $this->countFor($active[$date->toDateString()] ?? [], $storeId);

        $statusCounts = $this->statusEventCounts($storeIds, $from, $to, $weeks);
        $turnoverEvents = $this->mapTurnoverEvents($events, $stores, $activeAt);

        // Over the range: per employee (roster) and per store (labor, pay).
        $rangeWindow = [$this->window(0, $from, $to, $to)];
        $rangeRows = $this->metricStats($storeIds, $rangeWindow, $allColumnIds, byEmployee: true);
        $rangeStoreRows = $this->metricStats($storeIds, $rangeWindow, $allColumnIds)->groupBy('store_id');
        $overtimeRows = $this->overtimeRows($storeIds, $range ? $weeks : [[$from, $to]]);

        $employeeIds = $members->keys()
            ->merge($rangeRows->pluck('employee_id'))
            ->merge($overtimeRows->pluck('employee_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $employees = $this->employees($employeeIds);
        $positions = $this->latestPositionsAsOf($employeeIds, $to);

        $tenureRows = $this->tenureRows($members, $to, $employees, $stores);
        $overtimeRows = $this->decorateOvertime($overtimeRows, $employees, $positions, $stores);

        $weekWindows = array_map(fn (array $week, int $i) => $this->window($i, $week[0], $week[1], $week[1]), $weeks, array_keys($weeks));
        $trendRows = $this->metricStats($storeIds, $weekWindows, $this->resolveColumnIds(self::HIGHLIGHT_COLUMN_KEYS))
            ->groupBy('idx')
            ->map(fn (Collection $rows) => $rows->groupBy('store_id'));
        $finalLaborRows = $range ? $this->finalLaborRows($storeIds, $weeks) : collect();

        $block = function (?int $storeId, bool $withLists) use (
            $from, $to, $weeks, $range, $members, $activeAt, $statusCounts, $turnoverEvents, $rangeStoreRows,
            $overtimeRows, $positions, $tenureRows, $trendRows, $finalLaborRows, $allColumnIds
        ): array {
            $inStore = fn (Collection $items) => $storeId === null
                ? $items
                : $items->filter(fn (array $item) => $item['store_id'] === $storeId);

            $activeStart = $activeAt($from->subDay(), $storeId);
            $activeEnd = $activeAt($to, $storeId);
            $newHires = $this->countFor($statusCounts['range'], $storeId, 'new_hires');
            $separations = $this->countFor($statusCounts['range'], $storeId, 'separations');

            $memberIds = ($storeId === null ? $members : $members->filter(fn (int $s) => $s === $storeId))->keys()->all();
            $headcount = [
                'active_start' => $activeStart,
                'active_end' => $activeEnd,
                'new_hires' => $newHires,
                'separations' => $separations,
                'net_change' => $newHires - $separations,
                'by_position' => $positions->only($memberIds)
                    ->countBy()
                    ->sortDesc()
                    ->map(fn (int $count, string $position) => ['position' => $position, 'count' => $count])
                    ->values()
                    ->all(),
            ];

            $storeEvents = $inStore($turnoverEvents);
            $turnover = $this->turnoverBlock($storeEvents, $activeStart, $activeEnd, $withLists);
            $notable = $storeEvents->filter(function (array $event) {
                $impact = $event['impact_snapshot'];

                return ($impact['above_average_hours'] ?? false) || ($impact['above_average_performance'] ?? false);
            })->count();

            $rangeStats = $this->rawStats($storeId === null ? $rangeStoreRows->flatten(1) : $rangeStoreRows->get($storeId, collect()));
            $metrics = $this->formatStats($rangeStats, $allColumnIds);

            $trend = array_map(function (array $week, int $i) use ($activeAt, $statusCounts, $trendRows, $storeId) {
                $weekCounts = $statusCounts['weeks'][$i] ?? [];

                return $this->trendEntry(
                    $week,
                    $activeAt($week[0]->subDay(), $storeId),
                    $activeAt($week[1], $storeId),
                    $this->countFor($weekCounts, $storeId, 'new_hires'),
                    $this->countFor($weekCounts, $storeId, 'separations'),
                    $this->formatStats(
                        $this->rawStats($storeId === null
                            ? $trendRows->get($i, collect())->flatten(1)
                            : $trendRows->get($i, collect())->get($storeId, collect())),
                        $this->resolveColumnIds(self::HIGHLIGHT_COLUMN_KEYS),
                    ),
                );
            }, $weeks, array_keys($weeks));

            $storeOvertime = $inStore($overtimeRows);
            $overtime = $this->overtimeBlock($storeOvertime, $withLists);
            $averages = $this->averageTrendEntries(collect($trend));

            $result = [
                'summary' => [
                    'headcount_start' => $activeStart,
                    'headcount_end' => $activeEnd,
                    'new_hires' => $newHires,
                    'separations' => $separations,
                    'notable_departures' => $notable,
                    'turnover_rate_percent' => $turnover['turnover_rate_percent'],
                    'avg_weekly_gross_pay' => $averages['total_gross_pay'],
                    'avg_weekly_hours' => $averages['total_hours'],
                    'employee_weeks_over_40' => $overtime['over_40_count'],
                    'employee_weeks_over_60' => $overtime['over_60_count'],
                ],
                'headcount' => $headcount,
                'tenure' => $this->tenureBlock($inStore($tenureRows), $withLists),
                'turnover' => $turnover,
                'overtime' => $overtime,
                'labor' => [
                    'highlights' => $this->buildHighlights($metrics, $activeEnd),
                    'by_column' => collect($metrics)->filter(fn (array $m) => $m['count'] > 0)->all(),
                ],
                'trend' => $trend,
            ];

            if ($range) {
                $result['pay'] = $this->payBlock($rangeStats);
                $result['final_labor'] = $this->finalLabor($weeks, $finalLaborRows, $storeId);
            }

            return $result;
        };

        $combined = $block(null, true);
        $combined['roster'] = $this->buildRoster($members, $rangeRows, $employees, $positions, $to, $stores);
        if ($range) {
            $combined['birthdays'] = $this->buildBirthdays($combined['roster'], $from, $to);
        }

        $byStore = [];
        if ($range) {
            foreach ($storeIds as $storeId) {
                $byStore[$storeId] = $block($storeId, false);
            }
        }

        return ['combined' => $combined, 'by_store' => $byStore];
    }

    // -------------------------------------------------------------------------
    // Overtime / high-hours flags
    // -------------------------------------------------------------------------

    /**
     * Employee-weeks over 40 hours (one row per employee per business week),
     * summed in SQL per week and store; only rows over the line come back.
     */
    private function overtimeRows(array $storeIds, array $weeks): Collection
    {
        $columnIds = $this->resolveColumnIds(['total_hours']);
        $windows = array_map(fn (array $week, int $i) => $this->window($i, $week[0], $week[1], $week[1]), $weeks, array_keys($weeks));

        return $this->metricStats($storeIds, $windows, $columnIds, byEmployee: true, minSum: self::OVERTIME_HOURS_THRESHOLD)
            ->map(fn (object $row) => [
                'employee_id' => (int) $row->employee_id,
                'store_id' => (int) $row->store_id,
                'week_start' => $weeks[(int) $row->idx][0]->toDateString(),
                'week_end' => $weeks[(int) $row->idx][1]->toDateString(),
                'total_hours' => round((float) $row->sum_value, 4),
            ])
            ->filter(fn (array $row) => $row['total_hours'] > self::OVERTIME_HOURS_THRESHOLD)
            ->values();
    }

    /** Names, store and position on each row; most hours first, then name order. */
    private function decorateOvertime(Collection $rows, Collection $employees, Collection $positions, array $stores): Collection
    {
        $rank = $employees->keys()->flip();

        return $rows
            ->map(fn (array $row) => [
                'employee_id' => $row['employee_id'],
                'name' => $this->fullName($employees->get($row['employee_id'])),
                'store' => $stores[$row['store_id']],
                'position' => $positions->get($row['employee_id']),
                'week_start' => $row['week_start'],
                'week_end' => $row['week_end'],
                'total_hours' => $row['total_hours'],
                'overtime_hours' => round($row['total_hours'] - self::OVERTIME_HOURS_THRESHOLD, 2),
                'store_id' => $row['store_id'],
            ])
            ->sort(fn (array $a, array $b) => [$b['total_hours'], $rank[$a['employee_id']]] <=> [$a['total_hours'], $rank[$b['employee_id']]])
            ->values();
    }

    private function overtimeBlock(Collection $rows, bool $withLists): array
    {
        $over60 = $rows->filter(fn (array $row) => $row['total_hours'] >= self::HIGH_HOURS_THRESHOLD)->values();

        $block = [
            'over_40_hours_threshold' => self::OVERTIME_HOURS_THRESHOLD,
            'over_60_hours_threshold' => self::HIGH_HOURS_THRESHOLD,
            'over_40_count' => $rows->count(),
            'over_60_count' => $over60->count(),
        ];

        if ($withLists) {
            $block['over_40'] = $rows->map(fn (array $row) => Arr::except($row, 'store_id'))->values()->all();
            $block['over_60'] = $over60->map(fn (array $row) => Arr::except($row, 'store_id'))->values()->all();
        }

        return $block;
    }

    // -------------------------------------------------------------------------
    // Headcount events
    // -------------------------------------------------------------------------

    /**
     * Hires (hired/rehired) and separations (resigned/terminated) recorded at
     * the stores, counted for the range and for each trend week.
     *
     * @return array{range: array<int, array<string, int>>, weeks: array<int, array<int, array<string, int>>>}
     */
    private function statusEventCounts(array $storeIds, CarbonImmutable $from, CarbonImmutable $to, array $weeks): array
    {
        $first = $weeks === [] ? $from : $weeks[0][0];
        $last = $weeks === [] ? $to : $weeks[count($weeks) - 1][1];
        $hires = [EmployeeStatus::Hired->value, EmployeeStatus::Rehired->value];

        $rows = DB::table('employee_status_histories')
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', [...$hires, ...$this->separatedStatuses()])
            ->whereBetween('effective_date', [$first->min($from)->toDateString(), $last->max($to)->toDateString()])
            ->select('store_id', 'status', 'effective_date')
            ->selectRaw('COUNT(*) as n')
            ->groupBy('store_id', 'status', 'effective_date')
            ->get();

        $range = [];
        $byWeek = [];
        foreach ($rows as $row) {
            $kind = in_array($row->status, $hires, true) ? 'new_hires' : 'separations';
            $date = CarbonImmutable::parse($row->effective_date)->startOfDay();
            $storeId = (int) $row->store_id;
            $count = (int) $row->n;

            if ($date->betweenIncluded($from, $to)) {
                $range[$storeId][$kind] = ($range[$storeId][$kind] ?? 0) + $count;
            }

            if ($weeks !== [] && $date->betweenIncluded($first, $last)) {
                $week = intdiv((int) $first->diffInDays($date), 7);
                $byWeek[$week][$storeId][$kind] = ($byWeek[$week][$storeId][$kind] ?? 0) + $count;
            }
        }

        return ['range' => $range, 'weeks' => $byWeek];
    }

    /** One store's count, or every store's added up when $storeId is null. */
    private function countFor(array $byStore, ?int $storeId, ?string $kind = null): int
    {
        $value = fn ($entry) => (int) ($kind === null ? $entry : ($entry[$kind] ?? 0));

        if ($storeId !== null) {
            return isset($byStore[$storeId]) ? $value($byStore[$storeId]) : 0;
        }

        return array_sum(array_map($value, $byStore));
    }

    // -------------------------------------------------------------------------
    // Tenure
    // -------------------------------------------------------------------------

    /** Every employee active at the stores on $asOf with a current-stint start, in id order. */
    private function tenureRows(Collection $members, CarbonImmutable $asOf, Collection $employees, array $stores): Collection
    {
        $stintStarts = $this->currentStintStarts($members->keys(), $asOf);

        return $members->map(function (int $storeId, int $employeeId) use ($stintStarts, $employees, $stores, $asOf) {
            $start = $stintStarts->get($employeeId);

            if ($start === null || !$employees->has($employeeId)) {
                return null;
            }

            return [
                'employee_id' => $employeeId,
                'name' => $this->fullName($employees->get($employeeId)),
                'store' => $stores[$storeId],
                'hire_date' => $start->toDateString(),
                'tenure_days' => abs($asOf->diffInDays($start)),
                'store_id' => $storeId,
            ];
        })->filter()->values();
    }

    private function tenureBlock(Collection $rows, bool $withLists): array
    {
        $block = [
            'average_tenure_days' => $rows->isNotEmpty() ? round($rows->avg('tenure_days'), 1) : null,
            'distribution' => $this->tenureDistribution($rows),
        ];

        if ($withLists) {
            $strip = fn (array $row) => Arr::except($row, 'store_id');
            $block['newest_hires'] = $rows->sortBy('tenure_days')->take(self::TENURE_LIST_SIZE)->map($strip)->values()->all();
            $block['longest_tenured'] = $rows->sortByDesc('tenure_days')->take(self::TENURE_LIST_SIZE)->map($strip)->values()->all();
        }

        return $block;
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
     * Hired/rehired history on/before $upTo per employee, newest first — the
     * first entry on/before a date is the start of the stint current then.
     */
    private function hireHistory(Collection $employeeIds, CarbonInterface $upTo): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return EmployeeStatusHistory::query()
            ->whereIntegerInRaw('employee_id', $employeeIds->all())
            ->whereIn('status', [EmployeeStatus::Hired->value, EmployeeStatus::Rehired->value])
            ->whereDate('effective_date', '<=', $upTo->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['employee_id', 'effective_date'])
            ->groupBy('employee_id');
    }

    private function stintStartAsOf(?Collection $hires, CarbonInterface $asOf): ?CarbonInterface
    {
        return $hires?->first(fn (EmployeeStatusHistory $hire) => $hire->effective_date->lte($asOf))?->effective_date;
    }

    /** The start of each employee's stint current on $asOf. */
    private function currentStintStarts(Collection $employeeIds, CarbonInterface $asOf): Collection
    {
        return $this->hireHistory($employeeIds, $asOf)->map(fn (Collection $hires) => $hires->first()->effective_date);
    }

    // -------------------------------------------------------------------------
    // Turnover
    // -------------------------------------------------------------------------

    private function separationEvents(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return EmployeeStatusHistory::query()
            ->with('employee:id,first_name,middle_name,last_name')
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', $this->separatedStatuses())
            ->whereBetween('effective_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();
    }

    private function turnoverBlock(Collection $events, int $activeStart, int $activeEnd, bool $withEvents): array
    {
        $avgHeadcount = ($activeStart + $activeEnd) / 2;

        $byReason = $events
            ->groupBy(fn (array $e) => ($e['reason'] ?? 'unknown') . '|' . $e['type'])
            ->map(fn (Collection $group) => [
                'reason' => $group->first()['reason'],
                'type' => $group->first()['type'],
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        $count = $events->count();
        $turnoverRate = $avgHeadcount > 0
            ? round(($count / $avgHeadcount) * 100, 2)
            : ($count > 0 ? 100.0 : 0.0);

        $block = [
            'separations_count' => $count,
            'voluntary_count' => $events->where('type', 'voluntary')->count(),
            'involuntary_count' => $events->where('type', 'involuntary')->count(),
            'turnover_rate_percent' => $turnoverRate,
            'by_reason' => $byReason,
        ];

        if ($withEvents) {
            $block['events'] = $events->map(fn (array $e) => Arr::except($e, 'store_id'))->values()->all();
        }

        return $block;
    }

    /**
     * Each separation with its matched reason, tenure and impact snapshot. The
     * separation requests, hire history and both sides of every snapshot are
     * fetched once for all events.
     *
     * @param  callable(CarbonInterface, ?int): int  $activeAt
     */
    private function mapTurnoverEvents(Collection $events, array $stores, callable $activeAt): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        $employeeIds = $events->pluck('employee_id')->unique()->values();
        $eventStoreIds = $events->pluck('store_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $hires = $this->hireHistory($employeeIds, $events->last()->effective_date);

        $requests = SeparationRequest::query()
            ->whereIntegerInRaw('employee_id', $employeeIds->all())
            ->whereIn('store_id', $eventStoreIds)
            ->whereNotNull('final_working_day')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SeparationRequest $request) => $request->employee_id . '|' . $request->store_id . '|' . $request->separation_type->value);

        $mapped = $events->values()->map(function (EmployeeStatusHistory $event) use ($stores, $hires, $requests) {
            $type = $event->status === EmployeeStatus::Resigned ? 'voluntary' : 'involuntary';
            $separationDate = CarbonImmutable::parse($event->effective_date);
            $separationType = $type === 'voluntary' ? SeparationType::Resignation : SeparationType::Termination;

            $bestMatch = $requests->get($event->employee_id . '|' . $event->store_id . '|' . $separationType->value)
                ?->sortBy(fn (SeparationRequest $request) => abs($separationDate->diffInDays($request->final_working_day)))
                ->first();

            $reason = null;
            if ($bestMatch !== null) {
                $reason = $type === 'voluntary'
                    ? $bestMatch->resignation_reason?->value
                    : $bestMatch->termination_reason?->value;
            }

            $stintStart = $this->stintStartAsOf($hires->get($event->employee_id), $separationDate);
            $availableDays = $stintStart !== null ? abs($separationDate->diffInDays($stintStart)) : self::IMPACT_LOOKBACK_DAYS;
            $lookbackDays = max(1, min(self::IMPACT_LOOKBACK_DAYS, $availableDays));

            return [
                'employee_id' => $event->employee_id,
                'name' => $event->employee !== null ? $this->fullName($event->employee) : null,
                'store' => $stores[$event->store_id],
                'type' => $type,
                'effective_date' => $separationDate->toDateString(),
                'reason' => $reason,
                'reason_matched' => $reason !== null,
                'tenure_days' => $stintStart !== null ? abs($separationDate->diffInDays($stintStart)) : null,
                'store_id' => (int) $event->store_id,
                'lookback_days' => $lookbackDays,
                'window_start' => $separationDate->subDays($lookbackDays),
                'window_end' => $separationDate,
            ];
        });

        // The departing employee's own window, and the store's same window (shared between events).
        $columnIds = $this->resolveColumnIds(self::IMPACT_COLUMN_KEYS);
        $employeeWindows = [];
        $storeWindows = [];
        foreach ($mapped as $i => $event) {
            $employeeWindows[] = $this->window($i, $event['window_start'], $event['window_end'], $event['window_end'], employeeId: $event['employee_id']);

            $key = $this->storeWindowKey($event);
            $storeWindows[$key] ??= $this->window(count($storeWindows), $event['window_start'], $event['window_end'], $event['window_end'], storeId: $event['store_id']);
        }

        $employeeStats = $this->employeeWindowStats($employeeWindows, $columnIds)->groupBy('idx');
        $storeStats = $this->metricStats($eventStoreIds, array_values($storeWindows), $columnIds, windowStore: true)->groupBy('idx');

        return $mapped->map(function (array $event, int $i) use ($columnIds, $employeeStats, $storeStats, $storeWindows, $activeAt) {
            $snapshot = $this->buildImpactSnapshot(
                $event['lookback_days'],
                $this->formatStats($this->rawStats($employeeStats->get($i, collect())), $columnIds),
                $this->formatStats($this->rawStats($storeStats->get($storeWindows[$this->storeWindowKey($event)]['idx'], collect())), $columnIds),
                max($activeAt($event['window_end'], $event['store_id']), 1),
            );

            return Arr::except($event, ['lookback_days', 'window_start', 'window_end']) + ['impact_snapshot' => $snapshot];
        });
    }

    private function storeWindowKey(array $event): string
    {
        return $event['store_id'] . '|' . $event['window_start']->toDateString() . '|' . $event['window_end']->toDateString();
    }

    /**
     * "Did we lose a good one?" — the departing employee's own trailing metrics
     * vs. the store's average for the same window, so it's a transparent
     * comparison rather than an opaque composite score.
     */
    private function buildImpactSnapshot(float|int $lookbackDays, array $employeeStats, array $storeStats, int $storeActiveCount): array
    {
        $weeksInWindow = $lookbackDays / 7;

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

    /** Hourly pay and tips over the range, the figures Dashboard V1 shows for one week. */
    private function payBlock(array $rawStats): array
    {
        $get = fn (string $key, string $stat) => $rawStats[$this->columnsByKey->get($key)?->id][$stat] ?? null;
        $rounded = fn (?float $value) => $value !== null ? round($value, 2) : null;

        $totalTips = (float) ($get('total_tips', 'sum') ?? 0);
        $totalHours = (float) ($get('total_hours', 'sum') ?? 0);

        return [
            'average_hourly_pay' => $rounded($get('hourly_pay', 'avg')),
            'maximum_hourly_pay' => $rounded($get('hourly_pay', 'max')),
            'minimum_hourly_pay' => $rounded($get('hourly_pay', 'min')),
            'total_tips' => round($totalTips, 2),
            'total_hours' => round($totalHours, 2),
            'tips_per_hour' => $totalHours > 0 ? round($totalTips / $totalHours, 2) : 0,
        ];
    }

    /** Average labor per business week, everyone counted at the store they are at on the last week's end. */
    private function finalLaborRows(array $storeIds, array $weeks): Collection
    {
        if ($weeks === []) {
            return collect();
        }

        $asOf = $weeks[count($weeks) - 1][1];
        $windows = array_map(fn (array $week, int $i) => $this->window($i, $week[0], $week[1], $asOf), $weeks, array_keys($weeks));

        return $this->metricStats($storeIds, $windows, $this->resolveColumnIds(['labor']))->groupBy('idx');
    }

    /** One store's weekly labor %, or (combined) the mean of the stores' values that week. */
    private function finalLabor(array $weeks, Collection $rows, ?int $storeId): array
    {
        return array_map(function (array $week, int $i) use ($rows, $storeId) {
            $values = $rows->get($i, collect())
                ->filter(fn (object $row) => $row->avg_value !== null && ($storeId === null || (int) $row->store_id === $storeId))
                ->map(fn (object $row) => round((float) $row->avg_value * 100, 2));

            return [
                'week_start' => $week[0]->toDateString(),
                'week_end' => $week[1]->toDateString(),
                'labor' => $values->isNotEmpty() ? round($values->avg(), 2) : null,
            ];
        }, $weeks, array_keys($weeks));
    }

    /**
     * Deliberately not Collection::only() — EmployeeMetricColumn::query()->get()
     * returns an Eloquent Collection, whose only() filters by each model's
     * primary key (getKey()), not by the collection's array keys. Since
     * $columnsByKey is keyed by the string `key` column but each item's
     * primary key is still its integer id, only($keys) would silently compare
     * ids against key strings and always return empty.
     */
    private function resolveColumnIds(array $keys): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $column = $this->columnsByKey->get($key);

            if ($column !== null) {
                $ids[] = $column->id;
            }
        }

        return $ids;
    }

    // -------------------------------------------------------------------------
    // Trend (one entry per business week)
    // -------------------------------------------------------------------------

    private function trendEntry(array $week, int $activeStart, int $activeEnd, int $newHires, int $separations, array $metrics): array
    {
        $avgHeadcount = ($activeStart + $activeEnd) / 2;

        $turnoverRate = $avgHeadcount > 0
            ? round(($separations / $avgHeadcount) * 100, 2)
            : ($separations > 0 ? 100.0 : 0.0);

        $labor = $metrics['labor']['avg'] ?? null;

        return [
            'week_start' => $week[0]->toDateString(),
            'week_end' => $week[1]->toDateString(),
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

    /** The single-store page's trend: the weeks, their averages, and this week against them. */
    private function trendWithAverages(array $entries): array
    {
        $entries = collect($entries);
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
    // Roster and birthdays
    // -------------------------------------------------------------------------

    /**
     * Everyone active at the stores on the range's last day, plus anyone with
     * metrics in the range who is assigned to one of them then; their metrics
     * summed/averaged over the whole range (never per-day rows).
     */
    private function buildRoster(
        Collection $members,
        Collection $rangeRows,
        Collection $employees,
        Collection $positions,
        CarbonImmutable $asOf,
        array $stores
    ): array {
        $metricStores = $rangeRows->mapWithKeys(fn (object $row) => [(int) $row->employee_id => (int) $row->store_id]);
        $rosterStores = $metricStores->replace($members->all());

        if ($rosterStores->isEmpty()) {
            return [];
        }

        $employeeIds = $rosterStores->keys();
        $stintStarts = $this->currentStintStarts($employeeIds, $asOf);
        $statuses = $this->latestStatusesAsOf($employeeIds, $asOf);
        $pay = $this->latestPayAsOf($employeeIds, $asOf);
        $birthDates = $this->birthDates($employeeIds);
        $metricsByEmployee = $rangeRows->groupBy('employee_id');
        $columnIds = $this->columnsById->keys()->all();

        return $employees
            ->filter(fn (Employee $employee) => $rosterStores->has($employee->id))
            ->map(function (Employee $employee) use ($rosterStores, $stores, $stintStarts, $statuses, $positions, $pay, $birthDates, $metricsByEmployee, $columnIds, $asOf) {
                $stintStart = $stintStarts->get($employee->id);
                $status = $statuses->get($employee->id);
                $payRow = $pay->get($employee->id);

                $metrics = collect($this->formatStats($this->rawStats($metricsByEmployee->get($employee->id, collect())), $columnIds))
                    ->filter(fn (array $m) => $m['count'] > 0)
                    ->map(fn (array $m) => ['label' => $m['label'], 'sum' => $m['sum'], 'avg' => $m['avg']])
                    ->all();

                return [
                    'employee_id' => $employee->id,
                    'name' => $this->fullName($employee),
                    'store' => $stores[$rosterStores->get($employee->id)],
                    'position' => $positions->get($employee->id),
                    'status' => in_array($status, $this->separatedStatuses(), true) ? 'inactive' : 'active',
                    'hire_date' => $stintStart?->toDateString(),
                    'tenure_days' => $stintStart !== null ? abs($asOf->diffInDays($stintStart)) : null,
                    'base_pay' => $payRow?->base_pay,
                    'performance_pay' => $payRow?->performance_pay,
                    'birthday' => $birthDates->get($employee->id),
                    'metrics' => $metrics,
                ];
            })
            ->values()
            ->all();
    }

    /** Every roster birthday whose date falls inside the range (one row per year it does). */
    private function buildBirthdays(array $roster, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        foreach ($roster as $employee) {
            if ($employee['birthday'] === null) {
                continue;
            }

            $birth = CarbonImmutable::parse($employee['birthday']);

            for ($year = $from->year; $year <= $to->year; $year++) {
                $date = CarbonImmutable::create($year, $birth->month, $birth->day)->startOfDay();

                if ($date->betweenIncluded($from, $to)) {
                    $rows[] = [
                        'employee_id' => $employee['employee_id'],
                        'name' => $employee['name'],
                        'store' => $employee['store'],
                        'position' => $employee['position'],
                        'birth_date' => $birth->toDateString(),
                        'turns_age' => $year - $birth->year,
                        'date' => $date->toDateString(),
                    ];
                }
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);

        return $rows;
    }

    private function birthDates(Collection $employeeIds): Collection
    {
        return DB::table('employee_obsessions')
            ->whereIntegerInRaw('employee_id', $employeeIds->all())
            ->whereNotNull('birth_date')
            ->pluck('birth_date', 'employee_id')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString());
    }

    // -------------------------------------------------------------------------
    // Set-based lookups: many stores, many dates or windows, one query each
    // -------------------------------------------------------------------------

    /**
     * A date window for the derived tables below: metrics dated from..to, with
     * store membership taken on $asOf. A window can be pinned to one store or
     * one employee.
     */
    private function window(
        int $idx,
        CarbonInterface $from,
        CarbonInterface $to,
        CarbonInterface $asOf,
        ?int $storeId = null,
        ?int $employeeId = null
    ): array {
        return [
            'idx' => $idx,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'as_of' => $asOf->toDateString(),
            'store_id' => $storeId,
            'employee_id' => $employeeId,
        ];
    }

    /** The windows as an inline table (one SELECT per window, UNION ALL). */
    private function windowsTable(array $windows): Builder
    {
        $date = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'CAST(? AS DATE)' : '?';
        $columns = "? as idx, {$date} as from_date, {$date} as to_date, {$date} as as_of, ? as store_id, ? as employee_id";

        $table = null;
        foreach ($windows as $window) {
            $row = DB::query()->selectRaw($columns, [
                $window['idx'], $window['from'], $window['to'], $window['as_of'], $window['store_id'], $window['employee_id'],
            ]);
            $table = $table === null ? $row : $table->unionAll($row);
        }

        return $table;
    }

    /**
     * Window × the employees assigned to one of the stores on the window's
     * as_of date (their latest assignment on/before it). Each assignment is an
     * interval up to the employee's next assignment date.
     */
    private function assignedOn(array $storeIds, array $windows, bool $windowStore = false): Builder
    {
        $intervals = DB::table('employee_stores as es')
            ->whereIn('es.store_id', $storeIds)
            ->select('es.employee_id', 'es.store_id', 'es.effective_date as from_date')
            ->selectSub(fn (Builder $query) => $query
                ->from('employee_stores as later')
                ->selectRaw('MIN(later.effective_date)')
                ->whereColumn('later.employee_id', 'es.employee_id')
                ->whereColumn('later.effective_date', '>', 'es.effective_date'), 'next_date');

        $query = DB::query()
            ->fromSub($this->windowsTable($windows), 'w')
            ->joinSub($intervals, 'iv', fn ($join) => $join->on('iv.from_date', '<=', 'w.as_of'))
            ->where(fn (Builder $query) => $query->whereNull('iv.next_date')->orWhereColumn('iv.next_date', '>', 'w.as_of'));

        if ($windowStore) {
            $query->whereColumn('iv.store_id', 'w.store_id');
        }

        return $query;
    }

    /** As assignedOn(), minus anyone whose latest status on/before as_of is resigned/terminated. */
    private function activeOn(array $storeIds, array $windows): Builder
    {
        return $this->assignedOn($storeIds, $windows)->whereNotExists(fn (Builder $query) => $query
            ->from('employee_status_histories as h')
            ->whereColumn('h.employee_id', 'iv.employee_id')
            ->whereColumn('h.effective_date', '<=', 'w.as_of')
            ->whereIn('h.status', $this->separatedStatuses())
            ->whereNotExists(fn (Builder $newer) => $newer
                ->from('employee_status_histories as h2')
                ->whereColumn('h2.employee_id', 'h.employee_id')
                ->whereColumn('h2.effective_date', '<=', 'w.as_of')
                ->where(fn (Builder $later) => $later
                    ->whereColumn('h2.effective_date', '>', 'h.effective_date')
                    ->orWhere(fn (Builder $same) => $same
                        ->whereColumn('h2.effective_date', 'h.effective_date')
                        ->whereColumn('h2.id', '>', 'h.id')))));
    }

    /** Active employees at the stores on $asOf: employee id => store id, in id order. */
    private function activeMembersAsOf(array $storeIds, CarbonImmutable $asOf): Collection
    {
        if ($storeIds === []) {
            return collect();
        }

        return $this->activeOn($storeIds, [$this->window(0, $asOf, $asOf, $asOf)])
            ->select('iv.employee_id', 'iv.store_id')
            ->distinct()
            ->orderBy('iv.employee_id')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->employee_id => (int) $row->store_id]);
    }

    /**
     * Active headcount per store on each date.
     *
     * @param  list<string>  $dates
     * @return array<string, array<int, int>>  date => store id => count
     */
    private function activeCountsAsOf(array $storeIds, array $dates): array
    {
        if ($storeIds === [] || $dates === []) {
            return [];
        }

        $counts = [];
        foreach (array_chunk($dates, self::WINDOWS_PER_QUERY) as $chunk) {
            $windows = array_map(fn (string $date, int $i) => [
                'idx' => $i, 'from' => $date, 'to' => $date, 'as_of' => $date, 'store_id' => null, 'employee_id' => null,
            ], $chunk, array_keys($chunk));

            $rows = $this->activeOn($storeIds, $windows)
                ->select('w.idx', 'iv.store_id')
                ->selectRaw('COUNT(DISTINCT iv.employee_id) as n')
                ->groupBy('w.idx', 'iv.store_id')
                ->get();

            foreach ($rows as $row) {
                $counts[$chunk[(int) $row->idx]][(int) $row->store_id] = (int) $row->n;
            }
        }

        return $counts;
    }

    /**
     * SUM/AVG/MIN/MAX/COUNT over employee_metric_values per window, store and
     * column (and employee with $byEmployee), resolved by column id rather
     * than hardcoded IDs, so any metric column added later via CSV import shows
     * up automatically. Rows count the metrics of everyone assigned to the
     * store on the window's as_of date. $minSum keeps only groups whose sum is
     * above it.
     */
    private function metricStats(
        array $storeIds,
        array $windows,
        array $columnIds,
        bool $byEmployee = false,
        ?int $minSum = null,
        bool $windowStore = false
    ): Collection {
        if ($storeIds === [] || $windows === [] || $columnIds === []) {
            return collect();
        }

        return collect(array_chunk($windows, self::WINDOWS_PER_QUERY))
            ->flatMap(function (array $chunk) use ($storeIds, $columnIds, $byEmployee, $minSum, $windowStore) {
                $query = $this->assignedOn($storeIds, $chunk, $windowStore)
                    ->join('employee_metrics as em', function ($join) {
                        $join->on('em.employee_id', '=', 'iv.employee_id')
                            ->on('em.metric_date', '>=', 'w.from_date')
                            ->on('em.metric_date', '<=', 'w.to_date');
                    })
                    ->whereBetween('em.metric_date', $this->windowsSpan($chunk))
                    ->select('w.idx', 'iv.store_id')
                    ->groupBy('w.idx', 'iv.store_id');

                if ($byEmployee) {
                    $query->addSelect('em.employee_id')->groupBy('em.employee_id');
                }

                $this->withColumnStats($query, $columnIds);

                if ($minSum !== null) {
                    $query->havingRaw('SUM(emv.value_numeric) > ?', [$minSum]);
                }

                return $query->get();
            })
            ->values();
    }

    /** The same aggregates per window and column for windows pinned to one employee. */
    private function employeeWindowStats(array $windows, array $columnIds): Collection
    {
        if ($windows === [] || $columnIds === []) {
            return collect();
        }

        return collect(array_chunk($windows, self::WINDOWS_PER_QUERY))
            ->flatMap(fn (array $chunk) => $this->withColumnStats(
                DB::query()
                    ->fromSub($this->windowsTable($chunk), 'w')
                    ->join('employee_metrics as em', function ($join) {
                        $join->on('em.employee_id', '=', 'w.employee_id')
                            ->on('em.metric_date', '>=', 'w.from_date')
                            ->on('em.metric_date', '<=', 'w.to_date');
                    })
                    ->whereBetween('em.metric_date', $this->windowsSpan($chunk))
                    ->select('w.idx')
                    ->groupBy('w.idx'),
                $columnIds,
            )->get())
            ->values();
    }

    /**
     * The first and last metric date over the windows. Stated on the metrics
     * table itself so the planner reaches it by date index, not by scanning it
     * once per window.
     */
    private function windowsSpan(array $windows): array
    {
        return [min(array_column($windows, 'from')), max(array_column($windows, 'to'))];
    }

    private function withColumnStats(Builder $query, array $columnIds): Builder
    {
        return $query
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->whereIn('emv.column_id', $columnIds)
            ->addSelect('emv.column_id')
            ->groupBy('emv.column_id')
            ->selectRaw('SUM(emv.value_numeric) as sum_value')
            ->selectRaw('AVG(emv.value_numeric) as avg_value')
            ->selectRaw('MIN(emv.value_numeric) as min_value')
            ->selectRaw('MAX(emv.value_numeric) as max_value')
            ->selectRaw('COUNT(emv.value_numeric) as count_value');
    }

    /**
     * Folds stat rows into one figure per column: a lone row is taken as is,
     * several (stores, employees) are combined — sums and counts add up, the
     * average is the combined sum over the combined count.
     *
     * @return array<int, array{sum: ?float, avg: ?float, min: ?float, max: ?float, count: int}>
     */
    private function rawStats(Collection $rows): array
    {
        $float = fn ($value) => $value !== null ? (float) $value : null;
        $stats = [];

        foreach ($rows->groupBy('column_id') as $columnId => $group) {
            if ($group->count() === 1) {
                $row = $group->first();
                $stats[$columnId] = [
                    'sum' => $float($row->sum_value),
                    'avg' => $float($row->avg_value),
                    'min' => $float($row->min_value),
                    'max' => $float($row->max_value),
                    'count' => (int) $row->count_value,
                ];

                continue;
            }

            $values = fn (string $key) => $group->pluck($key)->reject(fn ($v) => $v === null)->map(fn ($v) => (float) $v);
            $sums = $values('sum_value');
            $count = (int) $group->sum(fn (object $row) => (int) $row->count_value);
            $sum = $sums->isNotEmpty() ? $sums->sum() : null;

            $stats[$columnId] = [
                'sum' => $sum,
                'avg' => ($sum !== null && $count > 0) ? $sum / $count : null,
                'min' => $values('min_value')->min(),
                'max' => $values('max_value')->max(),
                'count' => $count,
            ];
        }

        return $stats;
    }

    /**
     * One entry per requested column, keyed by column key.
     *
     * @return array<string, array{label: string, sum: ?float, avg: ?float, min: ?float, max: ?float, count: int}>
     */
    private function formatStats(array $rawStats, array $columnIds): array
    {
        $round = fn (?float $value) => $value !== null ? round($value, 4) : null;
        $result = [];

        foreach ($columnIds as $columnId) {
            $column = $this->columnsById->get($columnId);

            if ($column === null) {
                continue;
            }

            $stats = $rawStats[$columnId] ?? null;

            $result[$column->key] = [
                'label' => $column->label,
                'sum' => $round($stats['sum'] ?? null),
                'avg' => $round($stats['avg'] ?? null),
                'min' => $round($stats['min'] ?? null),
                'max' => $round($stats['max'] ?? null),
                'count' => $stats['count'] ?? 0,
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Per-employee "as of a date" lookups
    // -------------------------------------------------------------------------

    /** id => Employee, in last name / first name order. */
    private function employees(Collection $employeeIds): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return Employee::query()
            ->whereIntegerInRaw('id', $employeeIds->all())
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id')
            ->toBase();
    }

    private function latestStatusesAsOf(Collection $employeeIds, CarbonInterface $asOf): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $rows = EmployeeStatusHistory::query()
            ->whereIntegerInRaw('employee_id', $employeeIds->all())
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
            ->whereIntegerInRaw('employee_id', $employeeIds->all())
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
            ->whereIntegerInRaw('employee_id', $employeeIds->all())
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['employee_id', 'base_pay', 'performance_pay']);

        return $rows->unique('employee_id')->keyBy('employee_id');
    }

    // -------------------------------------------------------------------------
    // Small utilities
    // -------------------------------------------------------------------------

    private function separatedStatuses(): array
    {
        return [EmployeeStatus::Resigned->value, EmployeeStatus::Terminated->value];
    }

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
