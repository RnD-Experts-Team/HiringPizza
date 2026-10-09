<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use App\Services\Tcp\TcpEmployeeSyncService;
use App\Jobs\PublishOutboxEventJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes the loop opened by OperationsPizza when scheduling meets an employee
 * with no external link.
 *
 * Operations cannot create the staff record itself — HiringPizza owns employee
 * writes, and two writers to one external system is how duplicate people get
 * created. So it publishes a request, this handler pushes the employee into
 * TCP Manager+ (TCP's own connector then carries them into Humanity within
 * ~5 minutes), and the resulting hiring.v1.employee.updated event carries the
 * TCP id back. The Humanity id appears later via OperationsPizza's read-only
 * humanity:sync-employees matcher — nothing here writes Humanity.
 */
class EmployeeTcpSyncRequestedHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly TcpEmployeeSyncService $sync,
    ) {
    }

    public function handle(array $event): void
    {
        $employeeId = $this->asInt(
            data_get($event, 'data.employee_id') ?? data_get($event, 'employee_id')
        );

        if ($employeeId <= 0) {
            throw new \Exception('EmployeeTcpSyncRequestedHandler: missing/invalid employee_id');
        }

        $storeNumber = data_get($event, 'data.store_number');

        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            // Operations replicates from us, so it should not know an employee
            // we don't. ACK by throwing once, then the inbox parks it rather
            // than retrying forever.
            throw new \Exception("EmployeeTcpSyncRequestedHandler: employee {$employeeId} not found");
        }

        if (!$this->sync->enabled()) {
            Log::warning('TCP sync requested but writes are disabled', [
                'employee_id' => $employeeId,
                'store_number' => $storeNumber,
            ]);

            return;
        }

        // Already linked — Operations asked before its own replication caught
        // up. Re-emitting is still correct: it delivers the id it is waiting for.
        $before = $this->sync->existingTcpId($employee);

        $store = is_string($storeNumber) && $storeNumber !== ''
            ? Store::query()->where('store_number', $storeNumber)->first()
            : null;

        $tcpId = DB::transaction(function () use ($employee, $store) {
            return $this->sync->upsert($employee, $store);
        });

        Log::info('TCP sync request fulfilled', [
            'employee_id' => $employeeId,
            'tcp_employee_id' => $tcpId,
            'was_already_linked' => $before !== null,
        ]);

        $this->emitEmployeeUpdated($employee, is_string($storeNumber) ? $storeNumber : null);
    }

    /**
     * Re-emit the employee so OperationsPizza receives the new external id and
     * marks its sync request fulfilled. The `ids` collection is sent whole
     * because that is how hiring deltas ship collections.
     */
    private function emitEmployeeUpdated(Employee $employee, ?string $storeNumber): void
    {
        $employee->load('ids.idType');

        $ids = $employee->ids->map(fn ($row) => [
            'id_value' => $row->id_value,
            'id_type' => ['label' => $row->idType?->label],
        ])->values()->all();

        $factory = app(HiringEventFactory::class);
        $outbox = app(HiringOutboxService::class);

        $envelope = $factory->make('hiring.v1.employee.updated', [
            'employee_id' => $employee->id,
            'store_number' => $storeNumber,
            'changed_fields' => [
                'ids' => ['from' => null, 'to' => $ids],
            ],
        ]);

        $row = $outbox->record('hiring.v1.employee.updated', $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }
}
