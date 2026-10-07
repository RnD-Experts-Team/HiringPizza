<?php

namespace App\Services\Tcp;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\Store;
use App\Models\TcpStoreRole;
use Illuminate\Support\Facades\Log;

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
        ?int $employeeIdOverride = null,
    ): array {
        // A roleId assigns a role, and a role carries its OWN defaults for
        // certain fields — confirmed directly from a live rejection once
        // roleId started being sent: "location cannot be set without
        // overriding the employee role; workStatus cannot be set without
        // overriding the employee role; defaultJobCode cannot be set without
        // overriding the employee role." We always set those three
        // ourselves, so whenever a roleId is going out, the override flags
        // must go out true or TCP rejects our explicit values as conflicting
        // with the role's. With no roleId there is no role to conflict with,
        // so false (TCP's own example payload value) is correct as before.
        $roleId = $this->roleId($store);

        $payload = [
            'firstName' => $employee->first_name,
            'lastName' => $employee->last_name,
            // The only three fields in TCP's schema NOT documented as
            // nullable — every other field is "type | null", these are plain
            // "boolean". Omitting a non-nullable field is exactly what "The
            // cell must have a value" describes, so these are sent explicitly
            // rather than left to chance.
            'assignEmpAccess' => false,
            'infoOverrideRole' => $roleId !== null,
            'jobsOverrideRole' => $roleId !== null,
        ];

        if ($forCreate) {
            // TCP documents a null employeeId as auto-generating the next
            // available id. Whether this account actually relies on that, or
            // needs one supplied, is still being confirmed — see
            // config('tcp.assign_employee_id'). When true, we pick our own
            // rather than send our raw auto-increment id, which would collide
            // with the live roster's native ids ("5896"-style) almost
            // immediately. $employeeIdOverride lets the sync service retry
            // with the next candidate if this one is already taken.
            if (config('tcp.assign_employee_id', true)) {
                $payload['employeeId'] = (string) ($employeeIdOverride ?? $this->candidateEmployeeId($employee));
            }

            $hireDate = $this->latestHireDate($employee);

            // TCP requires hireDate and defaultJobCode on create.
            $payload['hireDate'] = $hireDate ?? now()->toDateString();
            $payload['defaultJobCode'] = (int) $this->defaultJobCode($employee, $store, $jobCodeCatalog);

            // TCP's field for carrying an id from an external system — OUR
            // employee id. This (not employeeId, which we now pick ourselves)
            // is what lets a timed-out create be recognised on retry, by
            // scanning the roster in TcpEmployeeSyncService::resolveRemoteId.
            $payload['exportCode'] = (string) $employee->id;
        }

        // middleName is deliberately absent: TCP's schema has no such field
        // (verified against https://api.tcplusondemand.com/v1/employees) —
        // sending it did nothing but clutter the payload.

        $email = $this->primaryContact($employee, 'email');

        if ($email !== null) {
            $payload['email'] = $email;
        }

        // TCP's field is `cell`, not `phone` — there is no `phone` field in
        // its schema. Sending the wrong key meant this value never reached
        // TCP at all, regardless of what it was — and per the account's own
        // rejection ("The cell must have a value", field "cell"), this cell
        // is one it actually enforces.
        $phone = $this->primaryContact($employee, 'phone');

        if ($phone !== null) {
            $payload['cell'] = $phone;
        }

        $employmentType = $employee->employment_type instanceof EmploymentType
            ? $employee->employment_type->value
            : $employee->employment_type;

        // TCP's field is `workStatus`, not `employeeType` — same problem as
        // `cell`/`phone`: the wrong key silently dropped this value.
        if ($employmentType !== null) {
            $payload['workStatus'] = $employmentType === 'W2' ? 'FullTime' : 'Contractor';
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

        // roleId is a plain string that, on this account, is a US state
        // postal code rather than a permission role (confirmed against the
        // live account, not TCP's general docs — see config('tcp.valid_role_ids')).
        // TCP does not derive it from `location` itself, and creating an
        // employee with none succeeds silently with the role left unset — so
        // this is applied unconditionally (create AND update), sourced only
        // from our local map, never a live TCP lookup. Resolved once, above,
        // so the override flags and this field never disagree.
        if ($roleId !== null) {
            $payload['roleId'] = $roleId;
        }

        // Deliberately absent: ssn and bank details. They are encrypted and
        // $hidden on the model, and clocking has no business with them.

        return $payload;
    }

    /**
     * The employeeId candidate for a create attempt.
     *
     * `offset + id*100 + attempt` reserves each employee its own block of 100
     * candidates, so one employee's retries can never collide with another
     * employee's base id — only with real TCP records, which is the case
     * TcpEmployeeSyncService's retry loop exists to recover from.
     */
    public function candidateEmployeeId(Employee $employee, int $attempt = 0): int
    {
        return (int) config('tcp.employee_id_offset') + ((int) $employee->id * 100) + $attempt;
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
     *
     * Throws instead of returning null: a missing defaultJobCode used to
     * reach TCP silently and come back as an opaque "The cell must have a
     * value". This isn't a new failure mode — that create already failed and
     * rolled back either way — it just makes the failure local and legible,
     * and stops burning a TCP call on a doomed request.
     */
    private function defaultJobCode(Employee $employee, ?Store $store, array $jobCodeCatalog): string
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

        if (filled($fallback)
            && collect($jobCodeCatalog)->contains(fn (array $code) => (string) $code['id'] === (string) $fallback)
        ) {
            return (string) $fallback;
        }

        throw new TcpJobCodeNotMappedException($label !== null
            ? "No TCP job code matches store {$storeNumber} for position '{$label}'. Run php artisan tcp:sync-job-codes, or set TCP_DEFAULT_JOB_CODE."
            : 'Employee has no position assigned, so no TCP job code can be resolved. Assign a position before creating, or set TCP_DEFAULT_JOB_CODE.');
    }

    /**
     * The store's mapped TCP roleId (tcp:sync-role-map / tcp:map-role), or
     * null if the store has none yet — never a live TCP lookup.
     *
     * A value outside tcp.valid_role_ids is refused rather than sent: it
     * would mean the local table went stale (an account-side role removed),
     * and a wrong-but-well-formed roleId is a worse failure than a missing
     * one, since it silently misassigns a person's state instead of just
     * leaving the field blank the way an unmapped store already does.
     */
    private function roleId(?Store $store): ?string
    {
        if ($store === null) {
            return null;
        }

        $roleId = TcpStoreRole::query()->where('store_number', $store->store_number)->value('role_id');

        if ($roleId === null) {
            Log::warning('No TCP role mapped for store; creating/updating without roleId', [
                'store_number' => $store->store_number,
            ]);

            return null;
        }

        if (!in_array($roleId, (array) config('tcp.valid_role_ids'), true)) {
            Log::warning('Mapped TCP roleId is outside tcp.valid_role_ids; refusing to send it', [
                'store_number' => $store->store_number,
                'mapped_role_id' => $roleId,
            ]);

            return null;
        }

        return $roleId;
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
