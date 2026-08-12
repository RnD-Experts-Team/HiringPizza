<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\EmployeeId;
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
 * Idempotent by construction: TCP's `employeeId` is client-supplied, so we set
 * it to our own employee id. A retry after a timeout finds the record the
 * previous attempt created instead of duplicating the person — and TCP, like
 * Humanity, has no bulk delete to undo that with.
 */
class TcpEmployeeSyncService
{
    private const JOB_CODE_CACHE_KEY = 'tcp:jobcodes:by-name';

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

        $employee->loadMissing([
            'statusHistories', 'contacts', 'addresses',
            'positions.position', 'stores.store', 'ids.idType',
        ]);

        $tcpId = $this->resolveRemoteId($employee);

        if ($tcpId !== null) {
            $this->client->updateEmployee(
                $tcpId,
                $this->mapper->toPayload($employee, $store, forCreate: false, jobCodeByPositionLabel: $this->jobCodesByName())
            );
        } else {
            $created = $this->client->createEmployee(
                $this->mapper->toPayload($employee, $store, forCreate: true, jobCodeByPositionLabel: $this->jobCodesByName())
            );

            $tcpId = $this->idFrom($created);
        }

        $this->storeTcpId($employee, $tcpId);

        return $tcpId;
    }

    /**
     * Find this employee in TCP, if they are already there.
     *
     * Checks our stored link first, then probes by the id we would have used.
     * That second step is what recovers from a create that timed out but
     * actually landed — the failure mode TCP's lack of an idempotency key
     * leaves open.
     */
    private function resolveRemoteId(Employee $employee): ?string
    {
        $stored = $this->existingTcpId($employee);

        if ($stored !== null && $this->client->getEmployee($stored) !== null) {
            return $stored;
        }

        if (config('tcp.use_our_employee_id')) {
            $candidate = (string) $employee->id;

            if ($this->client->getEmployee($candidate) !== null) {
                Log::info('Adopting an existing TCP employee found by our id', [
                    'employee_id' => $employee->id,
                ]);

                return $candidate;
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
        $label = (string) config('tcp.id_type_label', 'TCP ID');

        $idType = IdType::query()->firstOrCreate(
            ['label' => $label],
            ['description' => 'TCP Manager+ / TimeClock Plus employee id']
        );

        EmployeeId::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'id_type_id' => $idType->id],
            ['id_value' => $tcpId]
        );
    }

    public function existingTcpId(Employee $employee): ?string
    {
        $label = (string) config('tcp.id_type_label', 'TCP ID');

        $value = EmployeeId::query()
            ->where('employee_id', $employee->id)
            ->whereHas('idType', fn ($query) => $query->where('label', $label))
            ->value('id_value');

        return filled($value) ? (string) $value : null;
    }

    /**
     * position label -> TCP jobCodeId. Cached: TCP allows only 2500 calls a
     * day, and this would otherwise run on every employee write.
     */
    private function jobCodesByName(): array
    {
        return Cache::remember(self::JOB_CODE_CACHE_KEY, 3600, function () {
            $map = [];

            foreach ($this->client->listJobCodes() as $jobCode) {
                $name = $jobCode['name'] ?? $jobCode['description'] ?? null;
                $id = $jobCode['jobCodeId'] ?? $jobCode['id'] ?? null;

                if (is_string($name) && $id !== null && !is_array($id)) {
                    $map[$name] = (string) $id;
                }
            }

            return $map;
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
