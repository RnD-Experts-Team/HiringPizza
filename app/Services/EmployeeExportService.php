<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Store;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExportService
{
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
                'statusHistories' => fn($q) => $q->orderBy('effective_date', 'desc'),
                'stores' => fn($q) => $q->orderBy('effective_date', 'desc'),
                'obsession',
            ]);

            foreach ($query->chunk(500) as $employees) {
                foreach ($employees as $employee) {
                    fputcsv($handle, $this->buildRow($employee));
                }
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
}
