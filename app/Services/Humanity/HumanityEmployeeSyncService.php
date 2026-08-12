<?php

namespace App\Services\Humanity;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\IdType;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for pushing an employee into Humanity.
 *
 * Called from every employee write path in this service — create, update,
 * status change, and the separation decision — so there is exactly one place
 * that knows how a person reaches Humanity.
 *
 * Idempotent by construction: `eid` is always our employee id, so a retry after
 * a timeout finds the record the previous attempt created instead of
 * duplicating the person. Humanity has no idempotency key and no bulk delete,
 * so a duplicate roster is expensive to undo by hand.
 */
class HumanityEmployeeSyncService
{
    public function __construct(
        private readonly HumanityStaffClientInterface $client,
        private readonly HumanityEmployeeMapper $mapper,
    ) {
    }

    /**
     * TCP's connector owns the employee record when it is active, so we must
     * not write one. Checked before writes_enabled so that flipping the write
     * flag alone can never re-open this path by accident.
     */
    public function blockedByTcpConnector(): bool
    {
        return (bool) config('humanity.tcp_connector_active');
    }

    public function enabled(): bool
    {
        if ($this->blockedByTcpConnector()) {
            return false;
        }

        return (bool) config('humanity.writes_enabled');
    }

    /**
     * Push the employee and return their Humanity id.
     *
     * Throws on failure — the caller runs inside a DB transaction and relies on
     * the exception to roll the whole write back, so nothing may be swallowed.
     */
    public function upsert(Employee $employee, ?Store $store = null): ?string
    {
        if ($this->blockedByTcpConnector()) {
            // TCP's connector owns the employee record and re-syncs it every 5
            // minutes. Writing here would fight it, and could overwrite the
            // external id it matches on. Not an error — the employee is still
            // created locally and reaches Humanity via TCP.
            Log::info('Humanity employee push skipped: TCP connector owns employee data', [
                'employee_id' => $employee->id,
                'topology' => 'HiringPizza -> TCP Manager+ -> (TCP connector) -> Humanity',
            ]);

            return $this->existingHumanityId($employee);
        }

        if (!$this->enabled()) {
            // The flag that keeps production safe until the roster has been
            // matched to the existing hand-created records.
            Log::info('Humanity employee push skipped (writes disabled)', ['employee_id' => $employee->id]);

            return $this->existingHumanityId($employee);
        }

        $employee->loadMissing([
            'statusHistories', 'payHistories', 'contacts', 'addresses',
            'positions.position', 'stores.store', 'ids.idType',
        ]);

        $existingId = $this->existingHumanityId($employee);

        // Trust the remote lookup over our stored id: the local row can be
        // stale or missing, but eid is authoritative on Humanity's side.
        $remote = $this->client->findByEid((string) $employee->id);

        if ($remote === null && $existingId !== null) {
            $remote = $this->client->findByEid($existingId) ?? null;
        }

        $humanityId = $remote === null ? null : $this->idFrom($remote);

        if ($humanityId !== null) {
            $this->client->updateEmployee(
                $humanityId,
                $this->mapper->toPayload($employee, $store, forCreate: false)
            );
        } else {
            $humanityId = $this->create($employee, $store);
        }

        $this->storeHumanityId($employee, $humanityId);

        return $humanityId;
    }

    private function create(Employee $employee, ?Store $store): string
    {
        $payload = $this->mapper->toPayload($employee, $store, forCreate: true);

        // A duplicate email fails with the generic code 12 and no explanation,
        // so check first and adopt the existing record rather than leaving the
        // operator with an unactionable error.
        if (!empty($payload['email'])) {
            $byEmail = $this->client->findByEmail($payload['email']);

            if ($byEmail !== null) {
                $existingId = $this->idFrom($byEmail);

                Log::warning('Humanity already has an employee with this email; adopting it', [
                    'employee_id' => $employee->id,
                    'humanity_employee_id' => $existingId,
                    'email' => $payload['email'],
                ]);

                // Claim it by stamping our eid on it.
                $this->client->updateEmployee(
                    $existingId,
                    $this->mapper->toPayload($employee, $store, forCreate: false)
                );

                return $existingId;
            }
        }

        $created = $this->client->createEmployee($payload);

        return $this->idFrom($created);
    }

    /**
     * Store the id the same way the CSV importer stores Altametrics/Paychecks
     * ids — an id_types row plus an employee_ids row. It then reaches
     * OperationsPizza for free, because the employee snapshot already
     * serialises ids[].id_type.
     */
    public function storeHumanityId(Employee $employee, string $humanityId): void
    {
        $label = (string) config('humanity.id_type_label', 'Humanity ID');

        $idType = IdType::query()->firstOrCreate(
            ['label' => $label],
            ['description' => 'TCP Humanity scheduling system employee id']
        );

        EmployeeId::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'id_type_id' => $idType->id],
            ['id_value' => $humanityId]
        );
    }

    public function existingHumanityId(Employee $employee): ?string
    {
        $label = (string) config('humanity.id_type_label', 'Humanity ID');

        $value = EmployeeId::query()
            ->where('employee_id', $employee->id)
            ->whereHas('idType', fn ($query) => $query->where('label', $label))
            ->value('id_value');

        return filled($value) ? (string) $value : null;
    }

    private function idFrom(array $row): string
    {
        foreach (['id', 'employee_id', 'user_id'] as $key) {
            $value = $row[$key] ?? null;

            if ($value !== null && $value !== '' && !is_array($value)) {
                return (string) $value;
            }
        }

        throw new HumanityException('Humanity returned an employee record with no id.');
    }
}
