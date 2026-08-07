<?php

namespace App\Services\Humanity;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\Store;

/**
 * Employee -> Humanity staff payload.
 *
 * The scheduling-relevant fields all live in satellite tables with no "current"
 * pointer, so "current X" always means the row with max(effective_date),
 * tiebreak max(id) — the same rule EmployeeWorkflowService and
 * EmployeeQueryService use.
 */
class HumanityEmployeeMapper
{
    /** Humanity employee_type: 0 None, 1 Full Time, 2 Part Time, 5 Contractor. */
    private const EMPLOYEE_TYPE = [
        'W2' => 1,
        '1099' => 5,
    ];

    /** Humanity status: -1 Disabled, 0 Deactivated, 1 Activated. */
    private const ACTIVE_STATUSES = ['hired', 'rehired'];

    /**
     * @param  array<string, string>  $locationByStoreNumber  store_number -> Humanity location id
     * @param  array<string, string>  $positionByLabel        our position label -> Humanity position id
     */
    public function toPayload(
        Employee $employee,
        ?Store $store = null,
        bool $forCreate = true,
        array $locationByStoreNumber = [],
        array $positionByLabel = [],
    ): array {
        $status = $this->currentStatus($employee);

        $payload = [
            // The external id we control. Setting it is what makes the upsert
            // idempotent — a retried create finds the record it already made.
            'eid' => (string) $employee->id,
            'status' => in_array($status, self::ACTIVE_STATUSES, true) ? 1 : 0,
            'group' => (int) config('humanity.default_group', 5),
        ];

        // Humanity's own field-name asymmetry: POST takes fname/lname,
        // PUT takes first_name/last_name. Getting this wrong silently ignores
        // the name rather than erroring.
        if ($forCreate) {
            $payload['name'] = trim("{$employee->first_name} {$employee->last_name}");
            $payload['fname'] = $employee->first_name;
            $payload['lname'] = $employee->last_name;
            $payload['send_activation'] = config('humanity.send_activation') ? 1 : 0;
        } else {
            $payload['first_name'] = $employee->first_name;
            $payload['last_name'] = $employee->last_name;
        }

        if (filled($employee->middle_name)) {
            $payload['middle_name'] = $employee->middle_name;
        }

        $email = $this->primaryContact($employee, 'email');

        if ($email !== null) {
            $payload['email'] = $email;
            // Humanity requires a username; the email is the only stable
            // unique-ish value we hold.
            $payload['username'] = $email;
        }

        $phone = $this->primaryContact($employee, 'phone');

        if ($phone !== null) {
            $payload['cell_phone'] = $phone;
        }

        $hireDate = $this->latestHireDate($employee);

        if ($hireDate !== null) {
            $payload['work_start_date'] = $hireDate;
        }

        $wage = $this->currentWage($employee);

        if ($wage !== null) {
            $payload['wage'] = $wage;
        }

        $employmentType = $employee->employment_type instanceof EmploymentType
            ? $employee->employment_type->value
            : $employee->employment_type;

        if ($employmentType !== null && isset(self::EMPLOYEE_TYPE[$employmentType])) {
            $payload['employee_type'] = self::EMPLOYEE_TYPE[$employmentType];
            // 1 = Hourly; every position here is paid hourly.
            $payload['pay_type'] = 1;
        }

        $address = $this->primaryAddress($employee);

        if ($address !== null) {
            $payload += $address;
        }

        $storeNumber = $store?->store_number ?? $this->currentStoreNumber($employee);

        if ($storeNumber !== null && isset($locationByStoreNumber[$storeNumber])) {
            $payload['location'] = $locationByStoreNumber[$storeNumber];
        }

        $positionLabel = $this->currentPositionLabel($employee);

        if ($positionLabel !== null && isset($positionByLabel[$positionLabel])) {
            // CSV of Humanity Position ids.
            $payload[$forCreate ? 'positions' : 'addschedule'] = $positionByLabel[$positionLabel];
        }

        // Deliberately absent: ssn and bank details. They are encrypted and
        // $hidden on the model, and a scheduling system has no business with them.

        return $payload;
    }

    public function currentStatus(Employee $employee): ?string
    {
        $latest = $this->latestByEffectiveDate($employee->statusHistories);

        $status = $latest?->status;

        if ($status instanceof EmployeeStatus) {
            return $status->value;
        }

        return $status === null ? null : strtolower((string) $status);
    }

    public function isActive(Employee $employee): bool
    {
        return in_array($this->currentStatus($employee), self::ACTIVE_STATUSES, true);
    }

    private function latestHireDate(Employee $employee): ?string
    {
        $hires = $employee->statusHistories->filter(function ($row) {
            $status = $row->status instanceof EmployeeStatus ? $row->status->value : (string) $row->status;

            return in_array(strtolower($status), ['hired', 'rehired'], true);
        });

        return $this->latestByEffectiveDate($hires)?->effective_date?->toDateString();
    }

    private function currentWage(Employee $employee): ?float
    {
        $latest = $this->latestByEffectiveDate($employee->payHistories);

        return $latest === null ? null : (float) $latest->base_pay;
    }

    private function currentStoreNumber(Employee $employee): ?string
    {
        return $this->latestByEffectiveDate($employee->stores)?->store?->store_number;
    }

    private function currentPositionLabel(Employee $employee): ?string
    {
        return $this->latestByEffectiveDate($employee->positions)?->position?->label;
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
            'address' => $address->address_1,
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
