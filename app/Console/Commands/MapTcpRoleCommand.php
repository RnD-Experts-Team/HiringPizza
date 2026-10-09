<?php

namespace App\Console\Commands;

use App\Models\TcpStoreRole;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Manual side of the store -> TCP roleId map, for a store tcp:sync-role-map
 * had nothing to observe (no employees yet, or none with a role set).
 *
 * Rows written here have source='manual' and are never silently overwritten
 * by a later tcp:sync-role-map run (only --force does that).
 */
class MapTcpRoleCommand extends Command
{
    protected $signature = 'tcp:map-role
        {store? : store_number, e.g. 03795-00001}
        {role_id? : One of tcp.valid_role_ids, e.g. OH}
        {--list : List the current store -> roleId map instead of setting one}
        {--unmap= : store_number to remove from the map}';

    protected $description = 'Manually set (or list/remove) the TCP roleId for a store';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        $unmap = $this->option('unmap');
        if ($unmap !== null) {
            return $this->unmap($unmap);
        }

        $storeNumber = $this->argument('store');
        $roleId = $this->argument('role_id');

        if ($storeNumber === null || $roleId === null) {
            $this->error('Usage: tcp:map-role {store} {role_id}   (or --list / --unmap=<store>)');

            return self::FAILURE;
        }

        $validRoles = (array) config('tcp.valid_role_ids');

        if (!in_array($roleId, $validRoles, true)) {
            $this->error("'{$roleId}' is not in tcp.valid_role_ids: " . implode(', ', $validRoles));

            return self::FAILURE;
        }

        TcpStoreRole::query()->updateOrCreate(
            ['store_number' => $storeNumber],
            ['role_id' => $roleId, 'source' => 'manual', 'last_synced_at' => Carbon::now()]
        );

        $this->info("Store {$storeNumber} -> roleId {$roleId} (manual).");

        return self::SUCCESS;
    }

    private function list(): int
    {
        $rows = TcpStoreRole::query()->orderBy('store_number')->get();

        if ($rows->isEmpty()) {
            $this->info('No store -> roleId mappings yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['store_number', 'role_id', 'source', 'last_synced_at'],
            $rows->map(fn (TcpStoreRole $row) => [
                $row->store_number,
                $row->role_id,
                $row->source,
                optional($row->last_synced_at)->toDateTimeString() ?? '—',
            ])->all()
        );

        return self::SUCCESS;
    }

    private function unmap(string $storeNumber): int
    {
        $deleted = TcpStoreRole::query()->where('store_number', $storeNumber)->delete();

        $this->info($deleted > 0
            ? "Removed the roleId mapping for store {$storeNumber}."
            : "Store {$storeNumber} had no mapping.");

        return self::SUCCESS;
    }
}
