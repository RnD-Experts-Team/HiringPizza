<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\ExternalIdType;
use App\Models\IdType;
use App\Models\Store;
use App\Models\TcpJobCode;
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
 * This account has no auto-numbering, so WE choose `employeeId` on create
 * (TcpEmployeeMapper::candidateEmployeeId — offset far from the live roster's
 * native ids). A collision is therefore expected occasionally, not
 * exceptional, and createWithRetry() below tries the next candidate when TCP
 * rejects one. Idempotency across a *timed-out* create (a different problem —
 * the response never arrived, so we don't know if it landed) still comes from
 * `exportCode` (= our employee id): resolveRemoteId() recovers that case by
 * scanning the roster — TCP, like Humanity, has no bulk delete to undo a
 * duplicate with.
 */
class TcpEmployeeSyncService
{
    /** Extra employeeId candidates to try after the first is rejected. */
    private const CREATE_ID_RETRY_LIMIT = 4;

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
            $created = $this->createWithRetry($employee, $store);

            $tcpId = $this->idFrom($created);
        }

        $this->storeTcpId($employee, $tcpId);

        return $tcpId;
    }

    /**
     * Create, retrying with the next employeeId candidate when TCP rejects
     * THIS specific attempt.
     *
     * Only retries on a definitive synchronous rejection — TcpException with a
     * non-empty `errors` body, meaning TCP explicitly told us this record did
     * not get created (whether via a 4xx response or the 2xx-with-errors shape
     * TcpEmployeeClient also treats as a failure). An exception with no
     * `errors` (a timeout, a 5xx, a malformed response) is NOT retried here —
     * that is exactly the ambiguous case where a create may have landed
     * anyway, and resolveRemoteId()'s exportCode scan is the recovery path for
     * it, not a blind resend with a different id.
     *
     * A single attempt when config('tcp.assign_employee_id') is false: with no
     * id of ours in the payload, every attempt would be byte-identical, so
     * retrying would just spend the same rejection five times over.
     */
    private function createWithRetry(Employee $employee, ?Store $store): array
    {
        $catalog = $this->jobCodeCatalog();
        $lastException = null;

        $retryLimit = config('tcp.assign_employee_id', true) ? self::CREATE_ID_RETRY_LIMIT : 0;

        for ($attempt = 0; $attempt <= $retryLimit; $attempt++) {
            $payload = $this->mapper->toPayload(
                $employee,
                $store,
                forCreate: true,
                jobCodeCatalog: $catalog,
                employeeIdOverride: $this->mapper->candidateEmployeeId($employee, $attempt),
            );

            try {
                return $this->client->createEmployee($payload);
            } catch (TcpException $e) {
                if ($e->errors === []) {
                    throw $e;
                }

                $lastException = $e;

                Log::info('TCP employeeId candidate rejected, trying the next one', [
                    'employee_id' => $employee->id,
                    'attempted_tcp_employee_id' => $payload['employeeId'],
                    'attempt' => $attempt,
                ]);
            }
        }

        throw $lastException;
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
     * description. Read from the local mirror (tcp:sync-job-codes) rather
     * than hitting TCP live — TCP allows only 2500 calls a day, shared with
     * OperationsPizza, and this used to run on every employee write.
     *
     * @return array<int, array{id:string, description:string, store_number:?string, clockable:bool}>
     */
    private function jobCodeCatalog(): array
    {
        return TcpJobCode::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (TcpJobCode $row) => [
                'id' => $row->tcp_job_code_id,
                'description' => $row->description,
                'store_number' => $row->store_number,
                'clockable' => $row->clockable,
            ])
            ->all();
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
