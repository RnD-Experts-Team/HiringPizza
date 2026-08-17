<?php

namespace App\Services\Tcp;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\Store;

/**
 * Employee -> TCP Manager+ payload.
 *
 * "Current X" always means the row with max(effective_date), tiebreak max(id) —
 * the same rule EmployeeWorkflowService, EmployeeQueryService and
 * HumanityEmployeeMapper use. The scheduling-relevant fields all live in
 * satellite tables with no "current" pointer, so this has to be consistent or
 * the systems disagree about who someone is.
 */
class TcpEmployeeMapper
{
    private const ACTIVE_STATUSES = ['hired', 'rehired'];

    /**
     * @param  array<int, array{id:string, description:string, store_number:?string, clockable:bool}>  $jobCodeCatalog
     *         normalized TCP job codes, from TcpEmployeeSyncService::jobCodeCatalog()
     */
    public function toPayload(
        Employee $employee,
        ?Store $store = null,
        bool $forCreate = true,
        array $jobCodeCatalog = [],
    ): array {
        $payload = [
            'firstName' => $employee->first_name,
            'lastName' => $employee->last_name,
        ];

        if ($forCreate) {
            // employeeId is deliberately ABSENT: TCP then assigns the next
            // available id. The live TCP roster predates us with its own native
            // ids (5896-style), so supplying our auto-increment id would sooner
            // or later collide with a real person's record.
            $hireDate = $this->latestHireDate($employee);

            // TCP requires hireDate and defaultJobCode on create.
            $payload['hireDate'] = $hireDate ?? now()->toDateString();

            $jobCode = $this->defaultJobCode($employee, $store, $jobCodeCatalog);

            if ($jobCode !== null) {
                $payload['defaultJobCode'] = (int) $jobCode;
            }

            // TCP's field for carrying an id from an external system — OUR
            // employee id. With employeeId TCP-assigned, this is the only
            // marker that lets a timed-out create be recognised on retry.
            $payload['exportCode'] = (string) $employee->id;
        }

        if (filled($employee->middle_name)) {
            $payload['middleName'] = $employee->middle_name;
        }

        $email = $this->primaryContact($employee, 'email');

        if ($email !== null) {
            $payload['email'] = $email;
        }

        $phone = $this->primaryContact($employee, 'phone');

        if ($phone !== null) {
            $payload['phone'] = $phone;
        }

        $employmentType = $employee->employment_type instanceof EmploymentType
            ? $employee->employment_type->value
            : $employee->employment_type;

        if ($employmentType !== null) {
            $payload['employeeType'] = $employmentType === 'W2' ? 'FullTime' : 'Contractor';
        }

        $address = $this->primaryAddress($employee);

        if ($address !== null) {
            $payload += $address;
        }

        // Termination is how TCP learns someone has left. Without it they stay
        // clockable and schedulable after their last day.
        $termination = $this->terminationDate($employee);

        if ($termination !== null) {
            $payload['terminationDate'] = $termination;
        }

        if ($store !== null) {
            $payload['location'] = (string) $store->store_number;
        }

        // Deliberately absent: ssn and bank details. They are encrypted and
        // $hidden on the model, and clocking has no business with them.

        return $payload;
    }

    public function currentStatus(Employee $employee): ?string
    {
        $status = $this->latestByEffectiveDate($employee->statusHistories)?->status;

        if ($status instanceof EmployeeStatus) {
            return $status->value;
        }

        return $status === null ? null : strtolower((string) $status);
    }

    public function isActive(Employee $employee): bool
    {
        return in_array($this->currentStatus($employee), self::ACTIVE_STATUSES, true);
    }

    private function terminationDate(Employee $employee): ?string
    {
        if ($this->isActive($employee)) {
            return null;
        }

        return $this->latestByEffectiveDate($employee->statusHistories)?->effective_date?->toDateString();
    }

    private function latestHireDate(Employee $employee): ?string
    {
        $hires = $employee->statusHistories->filter(function ($row) {
            $status = $row->status instanceof EmployeeStatus ? $row->status->value : (string) $row->status;

            return in_array(strtolower($status), ['hired', 'rehired'], true);
        });

        return $this->latestByEffectiveDate($hires)?->effective_date?->toDateString();
    }

    /**
     * TCP's job codes are per-store — "Crew Member - 3795-01", attributed to a
     * store by a "Restaurant Id" custom field — while our position labels are
     * store-agnostic ("Crew Member"). So the match is: a clockable code for
     * THIS store whose description starts with the position label,
     * case-insensitive.
     */
    private function defaultJobCode(Employee $employee, ?Store $store, array $jobCodeCatalog): ?string
    {
        $label = $this->latestByEffectiveDate($employee->positions)?->position?->label;
        $storeNumber = $store?->store_number;

        if ($label !== null && $storeNumber !== null) {
            foreach ($jobCodeCatalog as $code) {
                if (($code['store_number'] ?? null) === (string) $storeNumber
                    && ($code['clockable'] ?? false)
                    && stripos((string) $code['description'], (string) $label) === 0
                ) {
                    return (string) $code['id'];
                }
            }
        }

        $fallback = config('tcp.default_job_code');

        return filled($fallback) ? (string) $fallback : null;
    }

    private function primaryContact(Employee $employee, string $type): ?string
    {
        $contact = $employee->contacts
            ->filter(fn ($row) => strtolower((string) ($row->contact_type->value ?? $row->contact_type)) === $type)
            ->sortByDesc('is_primary')
            ->first();

        $value = $contact?->contact_value;

        return filled($value) ? (string) $value : null;
    }

    private function primaryAddress(Employee $employee): ?array
    {
        $address = $employee->addresses->sortByDesc('is_primary')->first();

        if ($address === null) {
            return null;
        }

        return array_filter([
            'address1' => $address->address_1,
            'city' => $address->city,
            'state' => $address->state,
            'zip' => $address->zip_code,
        ], fn ($value) => filled($value));
    }

    /** max(effective_date), tiebreak max(id) — the house rule for "current". */
    private function latestByEffectiveDate($collection)
    {
        if ($collection === null) {
            return null;
        }

        return $collection
            ->sortBy([
                fn ($row) => optional($row->effective_date)?->timestamp ?? 0,
                fn ($row) => (int) $row->id,
            ])
            ->last();
    }
}
