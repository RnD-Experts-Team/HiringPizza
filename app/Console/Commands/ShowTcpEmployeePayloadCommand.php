<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Services\Tcp\TcpEmployeeMapper;
use App\Services\Tcp\TcpException;
use Illuminate\Console\Command;

/**
 * Renders the exact payload we would POST to TCP for an employee — without
 * sending it.
 *
 * TCP's create errors ("The cell must have a value") name no field, so the only
 * way to reason about a rejection is to see precisely what left this service.
 * Doing that by retrying the real call costs a request from a 2500/day quota
 * shared with OperationsPizza; this costs nothing and can be run repeatedly.
 */
class ShowTcpEmployeePayloadCommand extends Command
{
    protected $signature = 'tcp:show-employee-payload
        {employee : employees.id}
        {--store= : store_number to build the payload against; defaults to the employee\'s current store}
        {--update : Render the update payload instead of the create one}';

    protected $description = 'Print the TCP employee payload for an employee without calling TCP';

    public function handle(TcpEmployeeMapper $mapper): int
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

        $store = $this->resolveStore($employee);

        if ($store === null) {
            return self::FAILURE;
        }

        $catalog = TcpJobCode::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (TcpJobCode $row) => [
                'id' => $row->tcp_job_code_id,
                'description' => $row->description,
                'store_number' => $row->store_number,
                'clockable' => $row->clockable,
            ])
            ->all();

        if ($catalog === []) {
            $this->warn('Local job-code catalog is EMPTY — run php artisan tcp:sync-job-codes first.');
        }

        try {
            $payload = $mapper->toPayload(
                $employee,
                $store,
                forCreate: !$this->option('update'),
                jobCodeCatalog: $catalog,
            );
        } catch (TcpException $e) {
            $this->error('Payload could not be built: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Store: {$store->store_number}   Employee: {$employee->id}");
        $this->newLine();

        // POST bodies are arrays of records on this API — show that framing,
        // because "the cell must have a value" is row/column phrasing.
        $this->line(json_encode([$payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();

        $this->table(
            ['field', 'type', 'value'],
            collect($payload)->map(fn ($value, $key) => [
                $key,
                get_debug_type($value),
                is_scalar($value) ? var_export($value, true) : json_encode($value),
            ])->values()->all()
        );

        // Anything falsy here is a candidate for the empty "cell".
        $suspect = collect($payload)
            ->filter(fn ($value) => $value === null || $value === '' || $value === 0 || $value === [])
            ->keys()
            ->all();

        if ($suspect !== []) {
            $this->newLine();
            $this->warn('Present but empty/zero — likely candidates for TCP\'s complaint:');
            foreach ($suspect as $field) {
                $this->line("  {$field}");
            }
        }

        // Full field list from TCP's own docs (https://api.tcplusondemand.com/v1/employees).
        // assignEmpAccess/infoOverrideRole/jobsOverrideRole are the only three
        // NOT documented as nullable — everything else may legitimately be
        // absent, those three should never be missing from what we send.
        $requiredFields = ['assignEmpAccess', 'infoOverrideRole', 'jobsOverrideRole'];
        $nullableFields = [
            'address1', 'address2', 'birthDate', 'cell', 'city', 'classification', 'badgeNumber',
            'networkId', 'department', 'email', 'employeeId', 'roleId', 'managerId',
            'enableLockedPeriod', 'exportCode', 'firstName', 'gender', 'hireDate', 'home',
            'isSuspended', 'language', 'lastName', 'location', 'lockHoursBefore', 'officeExt',
            'office', 'scheduleGroup', 'seniorityDate', 'state', 'scheduleOrg', 'terminationDate',
            'timezone', 'workStatus', 'zip', 'smsAddress', 'defaultCostCode', 'defaultJobCode',
            'defaultPayRate',
        ];

        $this->newLine();
        $missingRequired = array_filter($requiredFields, fn ($f) => !array_key_exists($f, $payload));

        if ($missingRequired !== []) {
            $this->error('NOT sent, and NOT documented as nullable by TCP — top suspects:');
            foreach ($missingRequired as $field) {
                $this->line("  {$field}");
            }
            $this->newLine();
        }

        $this->line('<info>Other fields not sent (documented nullable — probably fine):</info>');
        foreach ($nullableFields as $field) {
            if (!array_key_exists($field, $payload)) {
                $this->line("  {$field}");
            }
        }

        return self::SUCCESS;
    }

    private function resolveStore(Employee $employee): ?Store
    {
        $storeNumber = $this->option('store');

        if ($storeNumber !== null) {
            $store = Store::query()->where('store_number', $storeNumber)->first();

            if ($store === null) {
                $this->error("Store {$storeNumber} not found.");
            }

            return $store;
        }

        $store = $employee->stores->sortByDesc('effective_date')->first()?->store;

        if ($store === null) {
            $this->error('Employee has no store assignment; pass --store=.');
        }

        return $store;
    }
}
