<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\Humanity\HumanityEmployeeSyncService;
use App\Services\HiringEvents\ModelChangeSet;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use App\Jobs\PublishOutboxEventJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes the loop opened by OperationsPizza when scheduling meets an employee
 * with no Humanity link.
 *
 * Operations cannot create the staff record itself — HiringPizza owns employee
 * writes, and two writers to one external system is how duplicate people get
 * created. So it publishes a request, this handler performs the push, and the
 * resulting hiring.v1.employee.updated event carries the id back.
 */
class EmployeeHumanitySyncRequestedHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly HumanityEmployeeSyncService $sync,
    ) {
    }

    public function handle(array $event): void
    {
        $employeeId = $this->asInt(
            data_get($event, 'data.employee_id') ?? data_get($event, 'employee_id')
        );

        if ($employeeId <= 0) {
            throw new \Exception('EmployeeHumanitySyncRequestedHandler: missing/invalid employee_id');
        }

        $storeNumber = data_get($event, 'data.store_number');

        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            // Operations replicates from us, so it should not know an employee
            // we don't. ACK by throwing once, then the inbox parks it rather
            // than retrying forever.
            throw new \Exception("EmployeeHumanitySyncRequestedHandler: employee {$employeeId} not found");
        }

        if (!$this->sync->enabled()) {
            Log::warning('Humanity sync requested but writes are disabled', [
                'employee_id' => $employeeId,
                'store_number' => $storeNumber,
            ]);

            return;
        }

        // Already linked — Operations asked before its own replication caught
        // up. Re-emitting is still correct: it delivers the id it is waiting for.
        $before = $this->sync->existingHumanityId($employee);

        $humanityId = DB::transaction(function () use ($employee) {
            return $this->sync->upsert($employee);
        });

        Log::info('Humanity sync request fulfilled', [
            'employee_id' => $employeeId,
            'humanity_employee_id' => $humanityId,
            'was_already_linked' => $before !== null,
        ]);

        $this->emitEmployeeUpdated($employee, $storeNumber);
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

        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }
}
