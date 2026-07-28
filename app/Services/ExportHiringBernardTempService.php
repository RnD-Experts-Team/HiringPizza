<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Store;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportHiringBernardTempService
{
    /**
     * Export employees to CSV format.
     *
     * If $store is provided, export only employees assigned to that store.
     * If $store is null, export all employees.
     */
    public function export(?Store $store = null): StreamedResponse
    {
        $filename = 'employee_export_' . date('Y-m-d_His') . '.csv';

        return new StreamedResponse(function () use ($store): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $this->headers());

            $query = Employee::query()
                ->with([
                    'statusHistories' => fn ($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'payHistories' => fn ($q) => $q->orderByDesc('effective_date')->orderByDesc('id'),
                    'contacts',
                    'addresses',
                    'availabilityDays',
                    'financialInfos',
                    'ids',
                    'positions',
                    'stores',
                    'maritals',
                    'attachments',
                    'obsession',
                ])
                ->when($store, function ($query) use ($store): void {
                    $query->whereHas('stores', function ($storeQuery) use ($store): void {
                        $storeQuery->where('store_id', $store->id);
                    });
                })
                ->orderBy('id');

            foreach ($query->cursor() as $employee) {
                /** @var Employee $employee */
                fputcsv($handle, $this->buildRow($employee));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function headers(): array
    {
        return [
            'Employee ID',
            'Paychex ID',
            'Emp Name',
            'First Name',
            'Middle Name',
            'Last Name',
            'Gender',
            'Employment Type',

            'Status',
            'Status Effective Date',

            'Store #',
            'Position',
            'Marital Status',

            'Hired Date',
            'Birthdate',

            'Base Pay',
            'Performance Pay',
            'Pay Effective Date',

            'Primary Contact Name',
            'Primary Contact Type',
            'Primary Contact Value',

            'Address Name',
            'Address 1',
            'Address 2',
            'City',
            'State',
            'Zip Code',
            'Country',

            'Financial Info',
            'Availability Days',

            'Employee IDs',

            'Attachments Count',
            'Attachment Names',
            'Attachment Types',
        ];
    }

    private function buildRow(Employee $employee): array
    {
        $latestStatusHistory = $this->getLatestStatusHistory($employee);
        $latestPayHistory = $this->latestByEffectiveDate($employee->payHistories);
        $latestStore = $this->latestByEffectiveDate($employee->stores);
        $latestPosition = $this->latestByEffectiveDate($employee->positions);
        $latestMarital = $this->latestByEffectiveDate($employee->maritals);
        $latestFinancialInfo = $this->latestByEffectiveDate($employee->financialInfos);

        $primaryContact = $this->primaryOrFirst($employee->contacts);
        $primaryAddress = $this->primaryOrFirst($employee->addresses);

        return $this->csvRow([
            $employee->id,
            $this->getPaychexId($employee),
            $this->getFullName($employee),
            $employee->first_name,
            $employee->middle_name,
            $employee->last_name,
            $this->enumValue($employee->gender),
            $this->enumValue($employee->employment_type),

            $this->getStatusValue($latestStatusHistory),
            $this->getStatusBasedEffectiveDate($employee, $latestStatusHistory),

            $this->getStoreNumber($latestStore),
            $this->getPositionLabel($latestPosition),
            $this->getMaritalStatusLabel($latestMarital),

            $this->getOldestHiredDate($employee),
            $this->getBirthdate($employee),

            $this->safeAttribute($latestPayHistory, 'base_pay'),
            $this->safeAttribute($latestPayHistory, 'performance_pay'),
            $this->formatDate($this->safeAttribute($latestPayHistory, 'effective_date')),

            $this->safeAttribute($primaryContact, 'contact_name'),
            $this->enumValue($this->safeAttribute($primaryContact, 'contact_type')),
            $this->safeAttribute($primaryContact, 'contact_value'),

            $this->safeAttribute($primaryAddress, 'address_name'),
            $this->safeAttribute($primaryAddress, 'address_1'),
            $this->safeAttribute($primaryAddress, 'address_2'),
            $this->safeAttribute($primaryAddress, 'city'),
            $this->safeAttribute($primaryAddress, 'state'),
            $this->safeAttribute($primaryAddress, 'zip_code'),
            $this->safeAttribute($primaryAddress, 'country'),

            $this->formatFinancialInfo($latestFinancialInfo),
            $this->formatAvailabilityDays($employee->availabilityDays),

            $this->formatEmployeeIds($employee->ids),

            $employee->attachments->count(),
            $this->formatAttachmentNames($employee->attachments),
            $this->formatAttachmentTypes($employee->attachments),
        ]);
    }

    private function getLatestStatusHistory(Employee $employee): mixed
    {
        return $employee->statusHistories()
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    private function getStatusBasedEffectiveDate(Employee $employee, mixed $latestStatusHistory): string
    {
        if (!$latestStatusHistory) {
            return '';
        }

        $latestStatus = $this->normalizeStatus(
            $this->safeAttribute($latestStatusHistory, 'status')
        );

        if ($this->isActiveHiringStatus($latestStatus)) {
            return $this->getOldestHiredDate($employee);
        }

        if ($this->isInactiveStatus($latestStatus)) {
            return $this->formatDate(
                $this->safeAttribute($latestStatusHistory, 'effective_date')
            );
        }

        return $this->formatDate(
            $this->safeAttribute($latestStatusHistory, 'effective_date')
        );
    }

    private function getOldestHiredDate(Employee $employee): string
    {
        $oldestHiredHistory = $employee->statusHistories
            ->filter(function ($statusHistory): bool {
                return $this->isActiveHiringStatus(
                    $this->normalizeStatus(
                        $this->safeAttribute($statusHistory, 'status')
                    )
                );
            })
            ->sort(function ($a, $b): int {
                $dateComparison = $this->effectiveDateSortValue($a) <=> $this->effectiveDateSortValue($b);

                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                return ((int) ($this->safeAttribute($a, 'id') ?? 0))
                    <=> ((int) ($this->safeAttribute($b, 'id') ?? 0));
            })
            ->first();

        if (!$oldestHiredHistory) {
            return '';
        }

        return $this->formatDate(
            $this->safeAttribute($oldestHiredHistory, 'effective_date')
        );
    }

    private function isActiveHiringStatus(?string $status): bool
    {
        return in_array($status, ['hired', 'rehired'], true);
    }

    private function isInactiveStatus(?string $status): bool
    {
        return in_array($status, ['terminated', 'resigned'], true);
    }

    private function normalizeStatus(mixed $status): ?string
    {
        $status = $this->enumValue($status);

        if ($status === null || $status === '') {
            return null;
        }

        return strtolower(trim((string) $status));
    }

    private function latestByEffectiveDate(Collection $items): mixed
    {
        if ($items->isEmpty()) {
            return null;
        }

        return $items
            ->sort(function ($a, $b): int {
                $dateComparison = $this->effectiveDateSortValue($b) <=> $this->effectiveDateSortValue($a);

                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                return ((int) ($this->safeAttribute($b, 'id') ?? 0))
                    <=> ((int) ($this->safeAttribute($a, 'id') ?? 0));
            })
            ->first();
    }

    private function effectiveDateSortValue(mixed $item): int
    {
        $effectiveDate = $this->safeAttribute($item, 'effective_date');

        if ($effectiveDate instanceof \Carbon\CarbonInterface) {
            return $effectiveDate->timestamp;
        }

        if ($effectiveDate) {
            return strtotime((string) $effectiveDate) ?: 0;
        }

        $createdAt = $this->safeAttribute($item, 'created_at');

        if ($createdAt instanceof \Carbon\CarbonInterface) {
            return $createdAt->timestamp;
        }

        if ($createdAt) {
            return strtotime((string) $createdAt) ?: 0;
        }

        return (int) ($this->safeAttribute($item, 'id') ?? 0);
    }

    private function primaryOrFirst(Collection $items): mixed
    {
        if ($items->isEmpty()) {
            return null;
        }

        return $items->firstWhere('is_primary', true)
            ?? $items->firstWhere('is_primary', 1)
            ?? $items->first();
    }

    private function getFullName(Employee $employee): string
    {
        $parts = [
            $employee->first_name,
            $employee->middle_name,
            $employee->last_name,
        ];

        return trim(implode(' ', array_filter($parts)));
    }

    private function getPaychexId(Employee $employee): string
    {
        $paychexId = $employee->ids
            ->firstWhere('id_type_id', 1);

        if (!$paychexId) {
            return '';
        }

        return (string) (
            $this->safeAttribute($paychexId, 'id_value') ?? ''
        );
    }

    private function getStatusValue(mixed $statusHistory): string
    {
        if (!$statusHistory) {
            return '';
        }

        return (string) $this->enumValue(
            $this->safeAttribute($statusHistory, 'status')
        );
    }

    private function getStoreNumber(mixed $employeeStore): string
    {
        if (!$employeeStore) {
            return '';
        }

        return (string) (
            $employeeStore->store?->store_number
            ?? $this->safeAttribute($employeeStore, 'store_number')
            ?? $this->safeAttribute($employeeStore, 'store_id')
            ?? ''
        );
    }

    private function getPositionLabel(mixed $employeePosition): string
    {
        if (!$employeePosition) {
            return '';
        }

        return (string) (
            $employeePosition->position?->label
            ?? $this->safeAttribute($employeePosition, 'label')
            ?? $this->safeAttribute($employeePosition, 'position_id')
            ?? ''
        );
    }

    private function getMaritalStatusLabel(mixed $employeeMarital): string
    {
        if (!$employeeMarital) {
            return '';
        }

        return (string) (
            $employeeMarital->maritalStatus?->label
            ?? $this->safeAttribute($employeeMarital, 'label')
            ?? $this->safeAttribute($employeeMarital, 'marital_status_id')
            ?? $this->safeAttribute($employeeMarital, 'status')
            ?? ''
        );
    }

    private function getBirthdate(Employee $employee): string
    {
        if (!$employee->obsession) {
            return '';
        }

        return $this->formatDate(
            $this->safeAttribute($employee->obsession, 'birth_date')
        );
    }

    private function formatFinancialInfo(mixed $financialInfo): string
    {
        if (!$financialInfo) {
            return '';
        }

        /*
         * Do not read full account_number or routing_number here.
         * If the current APP_KEY does not match the key used to encrypt the data,
         * Laravel throws: "The MAC is invalid."
         */

        $parts = [
            'payment_method' => $this->enumValue($this->safeAttribute($financialInfo, 'payment_method')),
            'bank_name' => $this->safeAttribute($financialInfo, 'bank_name'),
            'account_type' => $this->enumValue($this->safeAttribute($financialInfo, 'account_type')),
            'account_last_four' => $this->safeAttribute($financialInfo, 'account_last_four'),
            'tax_filing_status' => $this->enumValue($this->safeAttribute($financialInfo, 'tax_filing_status')),
        ];

        return collect($parts)
            ->filter()
            ->map(fn ($value, $key): string => "{$key}: {$this->csvValue($value)}")
            ->implode('; ');
    }

    private function formatAvailabilityDays(Collection $availabilityDays): string
    {
        return $availabilityDays
            ->map(function ($day): ?string {
                $dayName = $this->enumValue(
                    $this->safeAttribute($day, 'day_of_week')
                    ?? $this->safeAttribute($day, 'day')
                    ?? $this->safeAttribute($day, 'weekday')
                );

                $start = $this->safeAttribute($day, 'start_time');
                $end = $this->safeAttribute($day, 'end_time');

                if ($dayName && $start && $end) {
                    return "{$this->csvValue($dayName)}: {$this->csvValue($start)}-{$this->csvValue($end)}";
                }

                if ($dayName) {
                    return (string) $this->csvValue($dayName);
                }

                return null;
            })
            ->filter()
            ->implode('; ');
    }

    private function formatEmployeeIds(Collection $ids): string
    {
        return $ids
            ->map(function ($employeeId): ?string {
                $type = $this->enumValue(
                    $this->safeAttribute($employeeId, 'id_type')
                    ?? $this->safeAttribute($employeeId, 'type')
                    ?? $this->safeAttribute($employeeId, 'document_type')
                    ?? $this->safeAttribute($employeeId, 'name')
                );

                $rawValue = $this->safeAttribute($employeeId, 'id_value')
                    ?? $this->safeAttribute($employeeId, 'value')
                    ?? $this->safeAttribute($employeeId, 'number')
                    ?? $this->safeAttribute($employeeId, 'document_number');

                $value = $this->maskSensitiveValue($rawValue);

                if ($type && $value) {
                    return "{$this->csvValue($type)}: {$this->csvValue($value)}";
                }

                return $value;
            })
            ->filter()
            ->implode('; ');
    }

    private function formatAttachmentNames(Collection $attachments): string
    {
        return $attachments
            ->map(function ($attachment): ?string {
                $value = $this->safeAttribute($attachment, 'file_name')
                    ?? $this->safeAttribute($attachment, 'filename')
                    ?? $this->safeAttribute($attachment, 'name')
                    ?? $this->safeAttribute($attachment, 'original_name');

                return $value ? (string) $this->csvValue($value) : null;
            })
            ->filter()
            ->implode('; ');
    }

    private function formatAttachmentTypes(Collection $attachments): string
    {
        return $attachments
            ->map(function ($attachment): mixed {
                return $this->safeAttribute($attachment, 'attachment_type')
                    ?? $this->safeAttribute($attachment, 'type')
                    ?? $this->safeAttribute($attachment, 'mime_type');
            })
            ->filter()
            ->map(fn ($value): mixed => $this->enumValue($value))
            ->unique()
            ->map(fn ($value): string => (string) $this->csvValue($value))
            ->implode('; ');
    }

    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    private function formatDate(mixed $date): string
    {
        $date = $this->enumValue($date);

        if (!$date) {
            return '';
        }

        if ($date instanceof \Carbon\CarbonInterface) {
            return $date->format('Y-m-d');
        }

        return (string) $date;
    }

    private function safeAttribute(mixed $model, string $attribute): mixed
    {
        if (!$model) {
            return null;
        }

        try {
            return $model->{$attribute} ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function csvRow(array $row): array
    {
        return array_map(fn ($value): mixed => $this->csvValue($value), $row);
    }

    private function csvValue(mixed $value): mixed
    {
        $value = $this->enumValue($value);

        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_object($value)) {
            return method_exists($value, '__toString')
                ? (string) $value
                : json_encode($value);
        }

        return $value;
    }

    private function maskSensitiveValue(mixed $value): ?string
    {
        $value = $this->safeScalar($value);

        if (!$value) {
            return null;
        }

        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }

        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }

    private function safeScalar(mixed $value): ?string
    {
        try {
            $value = $this->csvValue($value);

            if ($value === null || $value === '') {
                return null;
            }

            return (string) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }
}