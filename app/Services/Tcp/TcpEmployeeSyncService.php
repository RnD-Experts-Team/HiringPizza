<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\ExternalIdType;
use App\Models\IdType;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for pushing an employee into TCP Manager+.
 *
 * This replaces the direct Humanity push. TCP is the system of record for
 * employees, and its own connector propagates them to Humanity every 5 minutes:
 *
 *     HiringPizza -> TCP Manager+ -> (TCP connector) -> Humanity
 *
 * Writing to Humanity ourselves would make us a second writer on those records.
 *
 * TCP assigns the `employeeId` on create — the live roster predates us with
 * its own native ids, so supplying our auto-increment id risks colliding with
 * a real person's record. Idempotency comes from `exportCode` (= our employee
 * id): a retry after a timed-out create finds the record the previous attempt
 * made by scanning for it — and TCP, like Humanity, has no bulk delete to
 * undo a duplicate with.
 */
class TcpEmployeeSyncService
{
    private const JOB_CODE_CACHE_KEY = 'tcp:jobcodes:catalog';

    public function __construct(
        private readonly TcpEmployeeClientInterface $client,
        private readonly TcpEmployeeMapper $mapper,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('tcp.writes_enabled');
    }

    /**
     * Push and return the TCP employee id.
     *
     * Throws on failure — the caller runs inside a DB transaction and relies on
     * the exception to roll the whole write back, so nothing may be swallowed.
     */
    public function upsert(Employee $employee, ?Store $store = null): ?string
    {
        if (!$this->enabled()) {
            Log::info('TCP employee push skipped (writes disabled)', ['employee_id' => $employee->id]);

            return $this->existingTcpId($employee);
        }

        // Rollout guard: TCP is production-only, so while the allowlist is
        // set, employees of non-allowlisted stores are skipped (not failed —
        // the local write must still succeed for stores outside the pilot).
        $guard = app(\App\Services\External\ExternalWriteGuard::class);

        if ($guard->isRestricted() && ($store === null || !$guard->allows((string) $store->store_number))) {
            Log::info('TCP employee push skipped (store not allowlisted)', [
                'employee_id' => $employee->id,
                'store_number' => $store?->store_number,
            ]);

            return $this->existingTcpId($employee);
        }

        $employee->loadMissing([
            'statusHistories', 'contacts', 'addresses',
            'positions.position', 'stores.store', 'ids.idType',
        ]);

        $tcpId = $this->resolveRemoteId($employee);

        if ($tcpId !== null) {
            $this->client->updateEmployee(
                $tcpId,
                $this->mapper->toPayload($employee, $store, forCreate: false, jobCodeCatalog: $this->jobCodeCatalog())
            );
        } else {
            $created = $this->client->createEmployee(
                $this->mapper->toPayload($employee, $store, forCreate: true, jobCodeCatalog: $this->jobCodeCatalog())
            );

            $tcpId = $this->idFrom($created);
        }

        $this->storeTcpId($employee, $tcpId);

        return $tcpId;
    }

    /**
     * Find this employee in TCP, if they are already there.
     *
     * Checks our stored link first, then scans the roster for an exportCode
     * equal to our employee id. That second step is what recovers from a
     * create that timed out but actually landed — the failure mode TCP's
     * lack of an idempotency key leaves open. It only runs when no stored
     * link exists (i.e. on creates, which are rare), so the extra roster
     * read is a non-issue for the quota.
     */
    private function resolveRemoteId(Employee $employee): ?string
    {
        $stored = $this->existingTcpId($employee);

        if ($stored !== null && $this->client->getEmployee($stored) !== null) {
            return $stored;
        }

        $ourId = (string) $employee->id;

        foreach ($this->client->listEmployees() as $record) {
            if ((string) ($record['exportCode'] ?? '') === $ourId) {
                Log::info('Adopting an existing TCP employee found by exportCode', [
                    'employee_id' => $employee->id,
                    'tcp_employee_id' => $record['employeeId'] ?? $record['id'] ?? null,
                ]);

                return $this->idFrom($record);
            }
        }

        return null;
    }

    /**
     * Store the id the same way the CSV importer stores Altametrics/Paychecks
     * ids. It then reaches OperationsPizza for free, because the employee
     * snapshot already serialises ids[].id_type.
     */
    public function storeTcpId(Employee $employee, string $tcpId): void
    {
        $idType = IdType::query()->firstOrCreate(
            ['label' => ExternalIdType::TCP],
            ['description' => 'TCP Manager+ / TimeClock Plus employee id']
        );

        EmployeeId::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'id_type_id' => $idType->id],
            ['id_value' => $tcpId]
        );
    }

    public function existingTcpId(Employee $employee): ?string
    {
        $value = EmployeeId::query()
            ->where('employee_id', $employee->id)
            ->whereHas('idType', fn ($query) => $query->where('label', ExternalIdType::TCP))
            ->value('id_value');

        return filled($value) ? (string) $value : null;
    }

    /**
     * TCP's job-code catalog, normalized for the mapper: per-store attribution
     * comes from the "Restaurant Id" custom field, never from parsing the
     * description. Cached: TCP allows only 2500 calls a day, and this would
     * otherwise run on every employee write.
     *
     * @return array<int, array{id:string, description:string, store_number:?string, clockable:bool}>
     */
    private function jobCodeCatalog(): array
    {
        return Cache::remember(self::JOB_CODE_CACHE_KEY, 3600, function () {
            $catalog = [];

            foreach ($this->client->listJobCodes() as $jobCode) {
                $id = $jobCode['jobCodeId'] ?? $jobCode['id'] ?? null;
                $description = $jobCode['description'] ?? $jobCode['name'] ?? null;

                if ($id === null || is_array($id) || !is_string($description)) {
                    continue;
                }

                $storeNumber = null;

                foreach ((array) ($jobCode['customFields'] ?? []) as $field) {
                    if (is_array($field)
                        && strcasecmp(trim((string) ($field['description'] ?? '')), 'Restaurant Id') === 0
                    ) {
                        $value = trim((string) ($field['value'] ?? ''));
                        $storeNumber = $value === '' ? null : $value;

                        break;
                    }
                }

                $catalog[] = [
                    'id' => (string) $id,
                    'description' => $description,
                    'store_number' => $storeNumber,
                    'clockable' => (bool) ($jobCode['clockable'] ?? false),
                ];
            }

            return $catalog;
        });
    }

    private function idFrom(array $row): string
    {
        foreach (['employeeId', 'id'] as $key) {
            $value = $row[$key] ?? null;

            if ($value !== null && $value !== '' && !is_array($value)) {
                return (string) $value;
            }
        }

        throw new TcpException('TCP returned an employee record with no employeeId.');
    }
}
