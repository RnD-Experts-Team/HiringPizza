<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
class ManagerDashboardService
{

    private function isoBusinessWeek(CarbonImmutable $date): array
    {
        $start = $date->startOfWeek(CarbonInterface::TUESDAY);
        return [$start, $start->addDays(6)];
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
            ->whereHas('statusHistories', function ($q) {
                $q->whereNotIn('status', ['resigned', 'terminated'])
                    ->whereRaw('employee_status_histories.id = (
                      SELECT sh2.id FROM employee_status_histories sh2
                      WHERE sh2.employee_id = employee_status_histories.employee_id
                      ORDER BY sh2.effective_date DESC, sh2.id DESC LIMIT 1
                  )');
            })
            ->with([
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
                        ->select([
                            'employee_metrics.id',
                            'employee_metrics.employee_id',
                            'employee_metrics.metric_date',
                            'employee_metric_values.value',
                            'employee_metric_values.value_numeric',
                        ]);
                },
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return [
            'store_id' => $store->store_number,
            'date' => $day->toDateString(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'employees' => $employees->map(fn(Employee $emp) => $this->mapEmployee($emp, $day))->values()->all(),
        ];
    }

    private function mapEmployee(Employee $employee, CarbonImmutable $date): array
    {
        $latestPosition = $employee->positions->first();
        $latestPay = $employee->payHistories->first();
        $metricEntry = $this->resolveMetric($employee);

        return [
            'employee_id' => $employee->id,
            'name' => [
                'first' => $employee->first_name,
                'middle' => $employee->middle_name,
                'last' => $employee->last_name,
            ],
            'birthday' => $this->resolveBirthday($employee, $date),
            'position' => $latestPosition?->position?->label,
            'base_pay' => $latestPay ? number_format((float) $latestPay->base_pay, 2, '.', '') : null,
            'performance_pay' => $latestPay ? number_format((float) $latestPay->performance_pay, 2, '.', '') : null,
            'metric' => $metricEntry,
        ];
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

    private function resolveMetric(Employee $employee): ?array
    {
        $metric = $employee->metrics->sortByDesc('metric_date')->first();

        if ($metric === null) {
            return null;
        }

        return [
            'metric_date' => $metric->metric_date,
            'value' => $metric->value,
            'value_numeric' => $metric->value_numeric !== null ? (float) $metric->value_numeric : null,
        ];
    }
}
