<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\TcpStoreRole;
use App\Services\Tcp\TcpEmployeeClientInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Derives the store -> TCP roleId mapping from TCP's own roster, instead of
 * anyone typing it in by hand.
 *
 * TCP's employee-create/update API does not assign `roleId` from `location`
 * for us — there is no locations/roles catalog endpoint this account exposes,
 * and creating an employee with no roleId succeeds and simply leaves the role
 * unset. But the roleId TCP's own UI already has recorded on existing
 * employees IS real, human-entered ground truth — grouping it by `location`
 * recovers the mapping without guessing at it. Stores with no employees yet
 * (or none with a role set) have nothing to observe; use tcp:map-role for
 * those.
 *
 * Writes are 'observed'. A 'manual' row (tcp:map-role) is never overwritten
 * by this command without --force, so a deliberate correction survives a
 * later sync of dirty UI data.
 */
class SyncTcpRoleMapCommand extends Command
{
    protected $signature = 'tcp:sync-role-map
        {--check : Report what would change without writing anything}
        {--force : Overwrite existing manual mappings too}';

    protected $description = 'Derive the store -> TCP roleId map from roleIds already set on existing TCP employees';

    public function handle(TcpEmployeeClientInterface $tcp): int
    {
        $check = (bool) $this->option('check');
        $force = (bool) $this->option('force');
        $validRoles = (array) config('tcp.valid_role_ids');

        $employees = collect($tcp->listEmployees());
        $this->info(sprintf('Fetched %d TCP employee(s).', $employees->count()));

        $byStore = $employees
            ->filter(fn ($row) => filled($row['location'] ?? null))
            ->groupBy(fn ($row) => (string) $row['location']);

        $observed = 0;
        $skippedNoData = 0;
        $conflicts = [];
        $invalidSeen = [];

        foreach ($byStore as $storeNumber => $rows) {
            $roleIds = $rows->pluck('roleId')->filter(fn ($v) => filled($v))->map(fn ($v) => (string) $v);

            $invalid = $roleIds->reject(fn ($v) => in_array($v, $validRoles, true))->unique();
            if ($invalid->isNotEmpty()) {
                $invalidSeen[$storeNumber] = $invalid->values()->all();
            }

            $valid = $roleIds->filter(fn ($v) => in_array($v, $validRoles, true))->unique();

            if ($valid->isEmpty()) {
                $skippedNoData++;
                continue;
            }

            if ($valid->count() > 1) {
                $conflicts[$storeNumber] = $valid->values()->all();
                continue;
            }

            $roleId = $valid->first();
            $existing = TcpStoreRole::query()->where('store_number', $storeNumber)->first();

            if ($existing !== null && $existing->source === 'manual' && !$force) {
                continue;
            }

            $observed++;

            if ($check) {
                continue;
            }

            TcpStoreRole::query()->updateOrCreate(
                ['store_number' => $storeNumber],
                ['role_id' => $roleId, 'source' => 'observed', 'last_synced_at' => Carbon::now()]
            );
        }

        $this->table(['metric', 'count'], [
            ['stores with a role observed', $observed],
            ['stores with no valid roleId to observe', $skippedNoData],
            ['stores with conflicting roleIds', count($conflicts)],
        ]);

        foreach ($conflicts as $storeNumber => $roleIds) {
            $this->warn("  Store {$storeNumber}: conflicting roleIds among its employees: " . implode(', ', $roleIds) . ' — resolve by hand with tcp:map-role.');
        }

        foreach ($invalidSeen as $storeNumber => $roleIds) {
            $this->warn("  Store {$storeNumber}: employee(s) carry a roleId outside tcp.valid_role_ids: " . implode(', ', $roleIds) . ' — ignored, not treated as ground truth.');
        }

        // A store with real employees here but no observed role at all is the
        // gap tcp:map-role exists to close.
        $storesWithoutRole = Store::query()
            ->whereNotIn('store_number', TcpStoreRole::query()->pluck('store_number'))
            ->pluck('store_number');

        foreach ($storesWithoutRole as $storeNumber) {
            $this->warn("  Store {$storeNumber} has no TCP role mapped — new hires there will go in with no roleId until tcp:map-role or a future sync observes one.");
        }

        if ($check) {
            $this->info('Check mode — nothing was written.');
        }

        return self::SUCCESS;
    }
}
