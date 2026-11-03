<?php

namespace App\Services;

use App\Enums\ContactType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeHireHoursExportService
{
    /** employee_metric_columns.id of the hours column. */
    private const HOURS_COLUMN_ID = 3;

    private const WINDOW_WEEKS = 8;

    private const EMPLOYEE_CHUNK = 500;

    /**
     * One CSV row per hire (hired / rehired status entry) in the filters.
     *
     * Every filter is optional; none given exports every hire ever. $storeNumber
     * matches the store on the hire entry itself, $from / $to bound its date
     * (inclusive). "Times Shown" is how many rows the employee has in this export.
     *
     * Average hours is the hours total from the hire date through the following
     * 8 weeks (56 days), divided by 8. Latest hours is the employee's hours on
     * the newest date that has any hours entry at all, or 0 if they have none there.
     *
     * Avg hours (last 8 weeks) ends at the employee's last date with hours above 0:
     * the business week (Tuesday to Monday) holding that date plus the 7 weeks before
     * it, divided by 8. The same on every row of an employee.
     */
    public function export(?string $storeNumber = null, ?string $from = null, ?string $to = null): StreamedResponse
    {
        $filename = 'employee_hire_hours_export_' . date('Y-m-d_His') . '.csv';

        return new StreamedResponse(function () use ($storeNumber, $from, $to): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Store Number',
                'Full Name',
                'Phone Number',
                'Date of Hire',
                'Times Shown',
                'Avg Hours Per Week (First 8 Weeks)',
                'Latest Hours',
                'Avg Hours Per Week (Last 8 Weeks)',
            ]);

            $hires = $this->hires($storeNumber, $from, $to);
            $timesShown = $hires->countBy('employee_id');
            $latestDate = $this->latestHoursDate();

            foreach ($hires->groupBy('employee_id')->chunk(self::EMPLOYEE_CHUNK) as $chunk) {
                $employeeIds = $chunk->keys()->all();
                $phones = $this->phones($employeeIds);
                $hours = $this->hoursByEmployee($employeeIds);

                foreach ($chunk as $employeeId => $employeeHires) {
                    $employeeHours = $hours->get($employeeId, collect());
                    $latestHours = $latestDate === null
                        ? 0.0
                        : (float) $employeeHours->where('date', $latestDate)->sum('hours');
                    $lastEightWeeksAverage = $this->lastEightWeeksAverage($employeeHours);

                    foreach ($employeeHires as $hire) {
                        fputcsv($handle, [
                            (string) ($hire->store_number ?? ''),
                            $this->fullName($hire),
                            $phones->get($employeeId, ''),
                            $hire->hire_date,
                            $timesShown[$employeeId],
                            $this->averageWeeklyHours($employeeHours, $hire->hire_date),
                            round($latestHours, 2),
                            $lastEightWeeksAverage,
                        ]);
                    }
                }
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function hires(?string $storeNumber, ?string $from, ?string $to): Collection
    {
        return DB::table('employee_status_histories as esh')
            ->join('employees', 'employees.id', '=', 'esh.employee_id')
            ->leftJoin('stores', 'stores.id', '=', 'esh.store_id')
            ->whereIn('esh.status', ['hired', 'rehired'])
            ->when($storeNumber !== null, fn ($q) => $q->where('stores.store_number', $storeNumber))
            ->when($from !== null, fn ($q) => $q->whereDate('esh.effective_date', '>=', CarbonImmutable::parse($from)->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('esh.effective_date', '<=', CarbonImmutable::parse($to)->toDateString()))
            ->orderBy('esh.employee_id')
            ->orderBy('esh.effective_date')
            ->orderBy('esh.id')
            ->get([
                'esh.employee_id',
                'esh.effective_date',
                'employees.first_name',
                'employees.middle_name',
                'employees.last_name',
                'stores.store_number',
            ])
            ->each(fn ($hire) => $hire->hire_date = substr((string) $hire->effective_date, 0, 10));
    }

    /**
     * The newest date with any hours entry, across all employees.
     */
    private function latestHoursDate(): ?string
    {
        $date = DB::table('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->where('emv.column_id', self::HOURS_COLUMN_ID)
            ->max('em.metric_date');

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, Collection<int, object{date: string, hours: float}>>
     */
    private function hoursByEmployee(array $employeeIds): Collection
    {
        return DB::table('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->where('emv.column_id', self::HOURS_COLUMN_ID)
            ->whereIn('em.employee_id', $employeeIds)
            ->get(['em.employee_id', 'em.metric_date', 'emv.value_numeric'])
            ->map(fn ($row) => (object) [
                'employee_id' => $row->employee_id,
                'date' => substr((string) $row->metric_date, 0, 10),
                'hours' => (float) $row->value_numeric,
            ])
            ->groupBy('employee_id');
    }

    /**
     * Hours from the hire date through the next 8 weeks, per week.
     */
    private function averageWeeklyHours(Collection $employeeHours, string $hireDate): float
    {
        $start = CarbonImmutable::parse($hireDate);
        $end = $start->addWeeks(self::WINDOW_WEEKS)->subDay()->toDateString();

        $total = $employeeHours
            ->filter(fn ($row) => $row->date >= $hireDate && $row->date <= $end)
            ->sum('hours');

        return round($total / self::WINDOW_WEEKS, 2);
    }

    /**
     * Hours over the business week of the last date with hours, and the 7 weeks
     * before it, per week. 0 for someone who never had hours.
     */
    private function lastEightWeeksAverage(Collection $employeeHours): float
    {
        $lastDate = $employeeHours->where('hours', '>', 0)->max('date');

        if ($lastDate === null) {
            return 0.0;
        }

        $lastWeekStart = CarbonImmutable::parse($lastDate)->startOfWeek(CarbonInterface::TUESDAY);
        $start = $lastWeekStart->subWeeks(self::WINDOW_WEEKS - 1)->toDateString();
        $end = $lastWeekStart->addDays(6)->toDateString();

        $total = $employeeHours
            ->filter(fn ($row) => $row->date >= $start && $row->date <= $end)
            ->sum('hours');

        return round($total / self::WINDOW_WEEKS, 2);
    }

    /**
     * Primary phone number per employee, else their first one.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, string>
     */
    private function phones(array $employeeIds): Collection
    {
        return DB::table('employee_contacts')
            ->where('contact_type', ContactType::Phone->value)
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['employee_id', 'contact_value'])
            ->unique('employee_id')
            ->pluck('contact_value', 'employee_id')
            ->map(fn ($value) => (string) $value);
    }

    private function fullName(object $row): string
    {
        return trim(implode(' ', array_filter([
            $row->first_name,
            $row->middle_name,
            $row->last_name,
        ])));
    }
}
