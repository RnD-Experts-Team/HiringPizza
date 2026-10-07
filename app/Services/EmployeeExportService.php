<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExportService
{
    private const DEFAULT_SEPARATION_EFFECTIVE_FROM = '2026-01-01';

    /**
     * Export the full current/effective profile of every employee to CSV.
     *
     * One row per employee. Every "as of now" value (status, store, position,
     * marital status, pay, financial info) is the record carrying the latest
     * effective_date, tie-broken by the highest id. Multi-valued data that has
     * no effective_date (contacts, availability, ids, attachments) is exported
     * in full in a single packed column.
     *
     * @param Store|null $store Filter by store, or null to get all employees
     * @return StreamedResponse
     */
    public function export(?Store $store = null): StreamedResponse
    {
        $filename = 'employee_export_' . date('Y-m-d_His') . '.csv';

        $response = new StreamedResponse(function () use ($store) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $this->exportHeaders());

            $query = Employee::query()
                ->with([
                    'statusHistories' => fn($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'statusHistories.store',
                    'payHistories' => fn($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'stores' => fn($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'stores.store',
                    'positions' => fn($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'positions.position',
                    'maritals' => fn($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'maritals.maritalStatus',
                    'financialInfos' => fn($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'contacts',
                    'addresses',
                    'availabilityDays.times',
                    'ids.idType',
                    'attachments.attachmentType',
                    'obsession',
                ])
                ->when($store, fn($q) => $q->whereHas(
                    'stores',
                    fn($storeQuery) => $storeQuery->where('store_id', $store->id)
                ));

            // chunkById (not cursor) so the eager loads above actually apply.
            $query->orderBy('id')->chunkById(500, function ($employees) use ($handle): void {
                foreach ($employees as $employee) {
                    fputcsv($handle, $this->buildRow($employee));
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
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
     * Column headers for the full employee export.
     *
     * @return array<string>
     */
    private function exportHeaders(): array
    {
        return [
            'Employee ID',
            'Emp Name',
            'First Name',
            'Middle Name',
            'Last Name',
            'Gender',
            'Employment Type',
            'SSN (Last 4)',
            'Birthdate',
            'Age',

            'Status',
            'Status Effective Date',
            'Status Store #',
            'Status Notes',
            'Original Hire Date',
            'Current Hire Date',
            'Separation Date',
            'Tenure (Days)',

            'Store #',
            'Store Effective Date',
            'Position',
            'Position Effective Date',
            'Marital Status',
            'Marital Effective Date',

            'Base Pay',
            'Performance Pay',
            'Total Pay',
            'Pay Effective Date',

            'Primary Email',
            'Primary Phone',
            'Emergency Contact Name',
            'Emergency Contact Value',
            'All Contacts',

            'Address Name',
            'Address 1',
            'Address 2',
            'City',
            'State',
            'Zip Code',
            'Country',

            'Account Type',
            'Account Number (Last 4)',
            'Routing Number (Last 4)',
            'Financial Effective Date',

            'T-Shirt Size',
            'Religion',
            'Race',
            'Photo URL',
            'Obsession Notes',

            'Availability',
            'Employee IDs',

            'Attachments Count',
            'Attachment Types',
            'Attachment Names',

            'Created At',
            'Updated At',
        ];
    }

    /**
     * Build a single row of data for an employee
     *
     * @param Employee $employee
     * @return array<string>
     */
    private function buildRow(Employee $employee): array
    {
        $latestStatus = $employee->statusHistories->first();
        $latestStore = $this->latestByEffectiveDate($employee->stores);
        $latestPosition = $this->latestByEffectiveDate($employee->positions);
        $latestMarital = $this->latestByEffectiveDate($employee->maritals);
        $latestPay = $this->latestByEffectiveDate($employee->payHistories);
        $latestFinancial = $this->latestByEffectiveDate($employee->financialInfos);

        $primaryAddress = $this->primaryOrFirst($employee->addresses);
        $emergencyContact = $this->contactOfType($employee, ContactType::EmergencyContact);

        $originalHireDate = $this->getHireDate($employee, oldest: true);
        $currentHireDate = $this->getHireDate($employee, oldest: false);
        $separationDate = $this->getSeparationDate($employee);

        $basePay = $latestPay->base_pay ?? null;
        $performancePay = $latestPay->performance_pay ?? null;

        return [
            (string) $employee->id,
            $this->getFullName($employee),
            (string) $employee->first_name,
            (string) $employee->middle_name,
            (string) $employee->last_name,
            $this->enumValue($employee->gender),
            $this->enumValue($employee->employment_type),
            $this->lastFour(fn() => $employee->ssn),
            $this->getBirthdate($employee),
            $this->getAge($employee),

            $latestStatus ? $this->canonicalStatus($this->enumValue($latestStatus->status)) : '',
            $latestStatus ? $this->formatDateString($latestStatus->effective_date) : '',
            $latestStatus ? $this->storeNumberOf($latestStatus) : '',
            $latestStatus ? (string) $latestStatus->notes : '',
            $originalHireDate,
            $currentHireDate,
            $separationDate,
            $this->getTenureDays($currentHireDate, $separationDate),

            $latestStore ? $this->storeNumberOf($latestStore) : '',
            $latestStore ? $this->formatDateString($latestStore->effective_date) : '',
            $latestPosition?->position?->label ?? '',
            $latestPosition ? $this->formatDateString($latestPosition->effective_date) : '',
            $latestMarital?->maritalStatus?->label ?? '',
            $latestMarital ? $this->formatDateString($latestMarital->effective_date) : '',

            $basePay !== null ? (string) $basePay : '',
            $performancePay !== null ? (string) $performancePay : '',
            $basePay !== null || $performancePay !== null
            ? number_format((float) $basePay + (float) $performancePay, 2, '.', '')
            : '',
            $latestPay ? $this->formatDateString($latestPay->effective_date) : '',

            $this->contactValueOfType($employee, ContactType::Email),
            $this->contactValueOfType($employee, ContactType::Phone),
            (string) ($emergencyContact->contact_name ?? ''),
            (string) ($emergencyContact->contact_value ?? ''),
            $this->formatContacts($employee->contacts),

            (string) ($primaryAddress->address_name ?? ''),
            (string) ($primaryAddress->address_1 ?? ''),
            (string) ($primaryAddress->address_2 ?? ''),
            (string) ($primaryAddress->city ?? ''),
            (string) ($primaryAddress->state ?? ''),
            (string) ($primaryAddress->zip_code ?? ''),
            (string) ($primaryAddress->country ?? ''),

            $latestFinancial ? $this->enumValue($latestFinancial->account_type) : '',
            $latestFinancial ? $this->lastFour(fn() => $latestFinancial->account_number) : '',
            $latestFinancial ? $this->lastFour(fn() => $latestFinancial->routing_number) : '',
            $latestFinancial ? $this->formatDateString($latestFinancial->effective_date) : '',

            $employee->obsession ? $this->enumValue($employee->obsession->t_shirt) : '',
            $employee->obsession ? $this->enumValue($employee->obsession->religion) : '',
            $employee->obsession ? $this->enumValue($employee->obsession->race) : '',
            (string) ($employee->obsession->image_url ?? ''),
            (string) ($employee->obsession->notes ?? ''),

            $this->formatAvailability($employee->availabilityDays),
            $this->formatEmployeeIds($employee->ids),

            (string) $employee->attachments->count(),
            $this->formatAttachmentTypes($employee->attachments),
            $this->formatAttachmentNames($employee->attachments),

            $this->formatTimestamp($employee->created_at),
            $this->formatTimestamp($employee->updated_at),
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
     * Latest record of an effective-dated collection, tie-broken by highest id.
     */
    private function latestByEffectiveDate(Collection $items): mixed
    {
        if ($items->isEmpty()) {
            return null;
        }

        return $items
            ->sortByDesc(fn($item): string => sprintf(
                '%s|%020d',
                $this->formatDateString($item->effective_date ?? null),
                (int) ($item->id ?? 0)
            ))
            ->first();
    }

    private function primaryOrFirst(Collection $items): mixed
    {
        if ($items->isEmpty()) {
            return null;
        }

        return $items->firstWhere('is_primary', true) ?? $items->first();
    }

    private function contactOfType(Employee $employee, ContactType $type): mixed
    {
        $matching = $employee->contacts->filter(
            fn($contact): bool => $this->enumValue($contact->contact_type) === $type->value
        );

        return $this->primaryOrFirst($matching->values());
    }

    private function contactValueOfType(Employee $employee, ContactType $type): string
    {
        return (string) ($this->contactOfType($employee, $type)->contact_value ?? '');
    }

    private function storeNumberOf(mixed $record): string
    {
        return (string) ($record->store?->store_number ?? $record->store_id ?? '');
    }

    /**
     * Earliest (oldest: true) or latest (oldest: false) hired/rehired date.
     */
    private function getHireDate(Employee $employee, bool $oldest): string
    {
        $dates = $employee->statusHistories
            ->filter(fn($history): bool => $this->isStartStatus(
                $this->enumValue($history->status)
            ))
            ->map(fn($history): string => $this->formatDateString($history->effective_date))
            ->filter()
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return '';
        }

        return $oldest ? (string) $dates->first() : (string) $dates->last();
    }

    /**
     * Separation date, only when the employee is currently separated.
     */
    private function getSeparationDate(Employee $employee): string
    {
        $latestStatus = $employee->statusHistories->first();

        if (!$latestStatus) {
            return '';
        }

        if (!$this->isEndStatus($this->enumValue($latestStatus->status))) {
            return '';
        }

        return $this->formatDateString($latestStatus->effective_date);
    }

    /**
     * Days between the current hire date and the separation date (or today).
     */
    private function getTenureDays(string $hireDate, string $separationDate): string
    {
        if ($hireDate === '') {
            return '';
        }

        $start = strtotime($hireDate);
        $end = $separationDate !== '' ? strtotime($separationDate) : time();

        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return (string) (int) floor(($end - $start) / 86400);
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

    private function getAge(Employee $employee): string
    {
        $birthdate = $this->getBirthdate($employee);

        if ($birthdate === '') {
            return '';
        }

        $birth = new \DateTimeImmutable($birthdate);
        $today = new \DateTimeImmutable('today');

        if ($birth > $today) {
            return '';
        }

        return (string) $birth->diff($today)->y;
    }

    private function formatContacts(Collection $contacts): string
    {
        return $contacts
            ->map(function ($contact): ?string {
                $value = trim((string) $contact->contact_value);

                if ($value === '') {
                    return null;
                }

                $type = $this->enumValue($contact->contact_type);
                $name = trim((string) $contact->contact_name);
                $label = $name !== '' ? "{$type} ({$name})" : $type;

                return $contact->is_primary ? "{$label}: {$value} [primary]" : "{$label}: {$value}";
            })
            ->filter()
            ->implode('; ');
    }

    private function formatAvailability(Collection $availabilityDays): string
    {
        return $availabilityDays
            ->map(function ($day): ?string {
                $dayName = $this->enumValue($day->day_of_week);

                if ($dayName === '') {
                    return null;
                }

                $times = $day->relationLoaded('times')
                    ? $day->times
                        ->map(fn($time): string => sprintf(
                            '%s-%s',
                            substr((string) $time->available_from, 0, 5),
                            substr((string) $time->available_to, 0, 5)
                        ))
                        ->implode(', ')
                    : '';

                return implode(' ', array_filter([
                    $dayName,
                    $this->enumValue($day->shift_type),
                    $times,
                ]));
            })
            ->filter()
            ->implode('; ');
    }

    private function formatEmployeeIds(Collection $ids): string
    {
        return $ids
            ->map(function ($employeeId): ?string {
                $value = trim((string) $employeeId->id_value);

                if ($value === '') {
                    return null;
                }

                $label = $employeeId->idType?->label ?? (string) $employeeId->id_type_id;

                return "{$label}: {$value}";
            })
            ->filter()
            ->implode('; ');
    }

    private function formatAttachmentTypes(Collection $attachments): string
    {
        return $attachments
            ->map(fn($attachment): string => (string) ($attachment->attachmentType->label ?? ''))
            ->filter()
            ->unique()
            ->implode('; ');
    }

    private function formatAttachmentNames(Collection $attachments): string
    {
        return $attachments
            ->map(fn($attachment): string => (string) ($attachment->original_name ?? ''))
            ->filter()
            ->implode('; ');
    }

    private function formatTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return trim((string) $value);
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return trim((string) $value);
    }

    /**
     * Last four characters of an encrypted attribute.
     *
     * Reading it is wrapped because a mismatched APP_KEY makes Laravel throw
     * "The MAC is invalid." — a single unreadable row must not abort the export.
     *
     * @param callable(): mixed $resolver
     */
    private function lastFour(callable $resolver): string
    {
        try {
            $value = trim((string) $resolver());
        } catch (\Throwable) {
            return '';
        }

        if ($value === '') {
            return '';
        }

        return substr($value, -4);
    }

    private function isStartStatus(string $status): bool
    {
        $normalized = $this->normalizeStatus($status);

        return in_array($normalized, [
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
