<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Store;
use App\Services\Tcp\TcpEmployeeSyncService;
use App\Services\Tcp\TcpException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manually (re)pushes one employee to TCP — the create/update flow the normal
 * hire/edit workflow triggers automatically, run on demand.
 *
 * Exists for remediation: an employee already created before a mapper fix
 * (e.g. tcp:sync-role-map / tcp:map-role landing) needs a fresh PUT to pick
 * up the field that create missed. upsert() already routes to update when a
 * TCP id is on file, so this is safe to run on an existing TCP employee.
 */
class PushTcpEmployeeCommand extends Command
{
    protected $signature = 'tcp:push-employee
        {employee : employees.id}
        {--store= : store_number to push against; defaults to the employee\'s current store}';

    protected $description = 'Push (create or update) one employee to TCP right now';

    public function handle(TcpEmployeeSyncService $sync): int
    {
        $employee = Employee::query()->find((int) $this->argument('employee'));

        if ($employee === null) {
            $this->error("Employee {$this->argument('employee')} not found.");

            return self::FAILURE;
        }

        $employee->loadMissing([
            'statusHistories', 'contacts', 'addresses',
            'positions.position', 'stores.store', 'ids.idType',
        ]);

        $storeNumber = $this->option('store');
        $store = $storeNumber !== null
            ? Store::query()->where('store_number', $storeNumber)->first()
            : $employee->stores->sortByDesc('effective_date')->first()?->store;

        if ($store === null) {
            $this->error('No store to push against. Pass --store= or assign the employee a store.');

            return self::FAILURE;
        }

        if (!$sync->enabled()) {
            $this->error('TCP writes are disabled (TCP_WRITES_ENABLED=false).');

            return self::FAILURE;
        }

        try {
            $tcpId = DB::transaction(fn () => $sync->upsert($employee, $store));
        } catch (TcpException $e) {
            $this->error('TCP push failed: ' . $e->getMessage());
            if ($e->errors !== []) {
                $this->line(json_encode($e->errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::FAILURE;
        }

        $this->info("Pushed employee {$employee->id} to TCP (store {$store->store_number}) -> tcpId {$tcpId}.");

        return self::SUCCESS;
    }
}
