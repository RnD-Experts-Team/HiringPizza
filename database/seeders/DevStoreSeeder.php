<?php

namespace Database\Seeders;

use App\Models\Position;
use App\Models\Store;
use App\Services\EmployeeWorkflowService;
use Illuminate\Database\Seeder;

/**
 * A workable local store + one employee, created through the REAL workflow
 * service — so the TCP push path (against the fake by default) and the outbox
 * event both fire exactly as they would for a live hire. With nats:consume
 * running in OperationsPizza, the seeded employee replicates across, which
 * makes this the cheapest full local end-to-end check.
 *
 *   php artisan db:seed --class=DevStoreSeeder
 *
 * Store id/number mirror OperationsPizza's DevStoreSeeder so the two sides
 * agree on the same test store.
 */
class DevStoreSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::query()->updateOrCreate(
            ['id' => 9001],
            ['store_number' => '03795-99999']
        );

        Position::query()->firstOrCreate(['label' => 'Crew Member']);
        Position::query()->firstOrCreate(['label' => 'Manager']);

        if ($store->employeeStores()->exists()) {
            $this->command?->info('Dev store already has employees — nothing to do.');

            return;
        }

        $employee = app(EmployeeWorkflowService::class)->create($store, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'gender' => 'female',
            'ssn' => '000-00-0000',
            'employment_type' => 'W2',
            'contacts' => [
                ['contact_name' => 'Work', 'contact_type' => 'email', 'contact_value' => 'ada@example.test', 'is_primary' => true],
            ],
            'pay_history' => [
                ['base_pay' => 16.50, 'performance_pay' => 0, 'effective_date' => '2026-01-15'],
            ],
            'status_history' => [
                ['status' => 'hired', 'effective_date' => '2026-01-15'],
            ],
            'positions' => [
                ['position_id' => Position::query()->where('label', 'Crew Member')->value('id'), 'effective_date' => '2026-01-15'],
            ],
        ]);

        $this->command?->info("Dev store 03795-99999 ready with employee {$employee->id} (outbox event emitted).");
    }
}
