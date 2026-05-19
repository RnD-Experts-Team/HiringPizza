<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExportService
{
    private const DEFAULT_SEPARATION_EFFECTIVE_FROM = '2026-01-01';

    /**
     * Export employees to CSV format
     *
     * @param Store|null $store Filter by store, or null to get all employees
     * @return StreamedResponse
     */
    public function export(?Store $store = null): StreamedResponse
    {
        $filename = 'employee_export_' . date('Y-m-d_His') . '.csv';

        $response = new StreamedResponse(function () use ($store) {
            $handle = fopen('php://output', 'w');

            // Write headers
            fputcsv($handle, [
                'Emp Name',
                'Status',
                'Store #',
                'Hired Date',
                'Birthdate',
            ]);

            // Get employees
            $query = Employee::query();

            $query->with([
                'statusHistories' => fn($q) => $q->orderBy('effective_date', 'desc')->orderBy('id', 'desc'),
                'stores' => fn($q) => $q->orderBy('effective_date', 'desc')->orderBy('id', 'desc'),
                'stores.store',
                'obsession',
            ]);

            $query->orderBy('id')->get()->each(function (Employee $employee) use ($handle): void {
                fputcsv($handle, $this->buildRow($employee));
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);

        return $response;
    }

    /**
     * Export employee tenure history to CSV format
     *
     * @param string|null $from Include rows with start_date on/after this date (YYYY-MM-DD)
     * @param string|null $to Include rows with start_date on/before this date (YYYY-MM-DD)
     * @return StreamedResponse
     */
    public function exportTenure(?string $from = null, ?string $to = null): StreamedResponse
    {
        $filename = 'employee_tenure_export_' . date('Y-m-d_His') . '.csv';
        $fromDate = $from ? date('Y-m-d', strtotime($from)) : null;
        $toDate = $to ? date('Y-m-d', strtotime($to)) : null;

        $response = new StreamedResponse(function () use ($fromDate, $toDate) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Emp Name',
                'Store #',
                'Start Status',
                'Start Date',
                'End Status',
                'End Date',
            ]);

            $query = DB::table('employee_status_histories as esh')
                ->select([
                    'esh.id',
                    'esh.employee_id',
                    'esh.store_id',
                    'esh.status',
                    'esh.effective_date',
                    'employees.first_name',
                    'employees.middle_name',
                    'employees.last_name',
                    'stores.store_number',
                ])
                ->leftJoin('employees', 'employees.id', '=', 'esh.employee_id')
                ->leftJoin('stores', 'stores.id', '=', 'esh.store_id')
                ->orderBy('esh.employee_id')
                ->orderBy('esh.effective_date')
                ->orderBy('esh.id');

            $currentEmployeeId = null;
            $currentEmployeeName = '';
            $openStint = null;

            foreach ($query->cursor() as $row) {
                if ($currentEmployeeId !== $row->employee_id) {
                    if ($openStint !== null) {
                        $this->writeTenureRow($handle, $currentEmployeeName, $openStint, null, null, $fromDate, $toDate);
                    }

                    $currentEmployeeId = $row->employee_id;
                    $currentEmployeeName = $this->formatNameFromRow(
                        $row->first_name,
                        $row->middle_name,
                        $row->last_name
                    );
                    $openStint = null;
                }

                $status = $this->canonicalStatus($row->status);
                $effectiveDate = $this->formatDateString($row->effective_date);
                $storeNumber = (string) ($row->store_number ?? '');
                $storeId = $row->store_id;

                if ($storeNumber === '' && $storeId !== null) {
                    $storeNumber = (string) $storeId;
                }

                if ($this->isStartStatus($status)) {
                    if ($openStint !== null && $openStint['store_id'] !== $storeId) {
                        $this->writeTenureRow($handle, $currentEmployeeName, $openStint, null, null, $fromDate, $toDate);
                        $openStint = null;
                    }

                    if ($openStint === null) {
                        $openStint = [
                            'start_status' => $status,
                            'start_date' => $effectiveDate,
                            'store_number' => $storeNumber,
                            'store_id' => $storeId,
                        ];
                    }

                    continue;
                }

                if ($this->isEndStatus($status) && $openStint !== null) {
                    $this->writeTenureRow($handle, $currentEmployeeName, $openStint, $status, $effectiveDate, $fromDate, $toDate);
                    $openStint = null;
                }
            }

            if ($openStint !== null) {
                $this->writeTenureRow($handle, $currentEmployeeName, $openStint, null, null, $fromDate, $toDate);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);

        return $response;
    }

    /**
     * Export status history for employees terminated/resigned on or after a date.
     */
    public function exportSeparationStatusHistory(?string $effectiveFrom = null): StreamedResponse
    {
        $filename = 'employee_separation_status_history_' . date('Y-m-d_His') . '.csv';
        $fromDate = $effectiveFrom ? date('Y-m-d', strtotime($effectiveFrom)) : self::DEFAULT_SEPARATION_EFFECTIVE_FROM;

        $response = new StreamedResponse(function () use ($fromDate) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Store #',
                'Emp',
                'Status',
                'Effective Date',
            ]);

            $query = DB::table('employee_status_histories as esh')
                ->select([
                    'esh.employee_id',
                    'esh.store_id',
                    'esh.status',
                    'esh.effective_date',
                    'employees.first_name',
                    'employees.middle_name',
                    'employees.last_name',
                    'stores.store_number',
                ])
                ->leftJoin('employees', 'employees.id', '=', 'esh.employee_id')
                ->leftJoin('stores', 'stores.id', '=', 'esh.store_id')
                ->whereIn('esh.status', [
                    EmployeeStatus::Resigned->value,
                    EmployeeStatus::Terminated->value,
                ])
                ->whereDate('esh.effective_date', '>=', $fromDate)
                // Only include terminated/resigned if previous status is terminated/resigned or null
                ->whereRaw("
                (
                    select prev.status
                    from employee_status_histories as prev
                    where prev.employee_id = esh.employee_id
                      and prev.effective_date < esh.effective_date
                    order by prev.effective_date desc
                    limit 1
                ) is null
                or
                (
                    select prev.status
                    from employee_status_histories as prev
                    where prev.employee_id = esh.employee_id
                      and prev.effective_date < esh.effective_date
                    order by prev.effective_date desc
                    limit 1
                ) in (?, ?)
            ", [
                    EmployeeStatus::Resigned->value,
                    EmployeeStatus::Terminated->value,
                ])
                ->orderBy('esh.employee_id')
                ->orderByDesc('esh.effective_date');

            foreach ($query->cursor() as $row) {
                $storeNumber = (string) ($row->store_number ?? '');

                if ($storeNumber === '' && $row->store_id !== null) {
                    $storeNumber = (string) $row->store_id;
                }

                fputcsv($handle, [
                    $storeNumber,
                    $this->formatNameFromRow($row->first_name, $row->middle_name, $row->last_name),
                    $this->canonicalStatus($row->status),
                    $this->formatDateString($row->effective_date),
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);

        return $response;
    }

    /**
     * Build a single row of data for an employee
     *
     * @param Employee $employee
     * @return array<string>
     */
    private function buildRow(Employee $employee): array
    {
        $fullName = $this->getFullName($employee);
        $status = $this->getLatestStatus($employee);
        $storeNumber = $this->getLatestStoreNumber($employee);
        $hiredDate = $this->getLatestHiredDate($employee);
        $birthdate = $this->getBirthdate($employee);

        return [
            $fullName,
            $status,
            $storeNumber,
            $hiredDate,
            $birthdate,
        ];
    }

    /**
     * Get the full name of an employee (first + middle + last)
     */
    private function getFullName(Employee $employee): string
    {
        $parts = [
            $employee->first_name,
            $employee->middle_name,
            $employee->last_name,
        ];

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * Get the latest status based on effective_date
     */
    private function getLatestStatus(Employee $employee): string
    {
        $latestStatusHistory = $employee->statusHistories->first();

        if (!$latestStatusHistory) {
            return '';
        }

        return $latestStatusHistory->status->value;
    }

    /**
     * Get the latest store number based on effective_date
     */
    private function getLatestStoreNumber(Employee $employee): string
    {
        $latestStore = $employee->stores->first();

        if (!$latestStore || !$latestStore->store) {
            return '';
        }

        return (string) $latestStore->store->store_number;
    }

    /**
     * Get the latest effective date where status is "hired" or "rehired"
     */
    private function getLatestHiredDate(Employee $employee): string
    {
        $hiredStatuses = [
            EmployeeStatus::Hired,
            EmployeeStatus::Rehired,
            EmployeeStatus::OJE,
        ];

        $hiredHistory = $employee->statusHistories
            ->filter(fn($history) => in_array($history->status, $hiredStatuses, true))
            ->sortByDesc('effective_date')
            ->first();

        if (!$hiredHistory) {
            return '';
        }

        return $hiredHistory->effective_date->format('Y-m-d');
    }

    /**
     * Get the birthdate from the employee obsession record
     */
    private function getBirthdate(Employee $employee): string
    {
        if (!$employee->obsession || !$employee->obsession->birth_date) {
            return '';
        }

        return $employee->obsession->birth_date->format('Y-m-d');
    }

    private function isStartStatus(string $status): bool
    {
        $normalized = $this->normalizeStatus($status);

        return in_array($normalized, [
            $this->normalizeStatus(EmployeeStatus::OJE->value),
            $this->normalizeStatus(EmployeeStatus::Hired->value),
            $this->normalizeStatus(EmployeeStatus::Rehired->value),
        ], true);
    }

    private function isEndStatus(string $status): bool
    {
        $normalized = $this->normalizeStatus($status);

        return in_array($normalized, [
            $this->normalizeStatus(EmployeeStatus::Resigned->value),
            $this->normalizeStatus(EmployeeStatus::Terminated->value),
        ], true);
    }

    private function formatNameFromRow(?string $firstName, ?string $middleName, ?string $lastName): string
    {
        $parts = [
            $firstName,
            $middleName,
            $lastName,
        ];

        return trim(implode(' ', array_filter($parts)));
    }

    private function writeTenureRow($handle, string $employeeName, array $openStint, ?string $endStatus, ?string $endDate, ?string $fromDate, ?string $toDate): void
    {
        if (($openStint['start_date'] ?? '') === '') {
            return;
        }

        if (!$this->isStartDateInRange($openStint['start_date'], $fromDate, $toDate)) {
            return;
        }

        fputcsv($handle, [
            $employeeName,
            $openStint['store_number'],
            $openStint['start_status'],
            $openStint['start_date'],
            $endStatus ?? '',
            $endDate ?? '',
        ]);
    }

    private function isStartDateInRange(string $startDate, ?string $fromDate, ?string $toDate): bool
    {
        if ($fromDate !== null && $startDate < $fromDate) {
            return false;
        }

        if ($toDate !== null && $startDate > $toDate) {
            return false;
        }

        return true;
    }

    private function normalizeStatus(?string $status): string
    {
        return strtolower(trim((string) $status));
    }

    private function canonicalStatus(?string $status): string
    {
        return match ($this->normalizeStatus($status)) {
            'oje' => EmployeeStatus::OJE->value,
            'hired' => EmployeeStatus::Hired->value,
            'rehired' => EmployeeStatus::Rehired->value,
            'resigned' => EmployeeStatus::Resigned->value,
            'terminated' => EmployeeStatus::Terminated->value,
            default => (string) $status,
        };
    }

    private function formatDateString($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return '';
        }

        return substr($stringValue, 0, 10);
    }
}
