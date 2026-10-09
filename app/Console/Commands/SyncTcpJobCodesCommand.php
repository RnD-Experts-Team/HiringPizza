<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\TcpJobCode;
use App\Services\Tcp\TcpEmployeeClientInterface;
use Illuminate\Console\Command;

/**
 * Pulls TCP's job codes into the local catalog table that
 * TcpEmployeeSyncService::jobCodeCatalog() reads from.
 *
 * Job codes are attributed to stores via their "Restaurant Id" custom field,
 * not their description — "Crew Member - 3795-01" encodes the store only for
 * humans. Mirrors OperationsPizza's tcp:sync-catalog (job-codes half only —
 * this repo has no need for a TCP-locations mirror, since employee create
 * sends `location` as the store_number directly).
 *
 * Not scheduled: a new cron against TCP's shared 2500/day quota is a
 * deliberate step, run by hand.
 */
class SyncTcpJobCodesCommand extends Command
{
    protected $signature = 'tcp:sync-job-codes
        {--check : Report what would change without writing anything}';

    protected $description = 'Sync TCP job codes into the local catalog table';

    public function handle(TcpEmployeeClientInterface $tcp): int
    {
        $check = (bool) $this->option('check');

        $jobCodes = collect($tcp->listJobCodes())
            ->filter(fn ($row) => isset($row['jobCodeId']) || isset($row['id']));

        $this->info(sprintf('Fetched %d TCP job code(s).', $jobCodes->count()));

        $perStore = 0;
        $global = 0;

        foreach ($jobCodes as $row) {
            $storeNumber = $this->restaurantId($row);
            $storeNumber === null ? $global++ : $perStore++;

            if ($check) {
                continue;
            }

            TcpJobCode::query()->updateOrCreate(
                ['tcp_job_code_id' => (string) ($row['jobCodeId'] ?? $row['id'])],
                [
                    'description' => (string) ($row['description'] ?? $row['name'] ?? ''),
                    'store_number' => $storeNumber,
                    'clockable' => (bool) ($row['clockable'] ?? false),
                    'is_active' => (bool) ($row['active'] ?? true),
                    'last_synced_at' => now(),
                ]
            );
        }

        $this->table(['metric', 'count'], [
            ['per-store job codes', $perStore],
            ['company-wide job codes', $global],
        ]);

        // A store whose new hires cannot get a defaultJobCode is the failure
        // this table exists to prevent, so name the gaps now.
        foreach (Store::query()->pluck('store_number') as $storeNumber) {
            $count = $check
                ? $jobCodes->filter(fn ($row) => $this->restaurantId($row) === $storeNumber && ($row['clockable'] ?? false))->count()
                : TcpJobCode::query()->where('store_number', $storeNumber)->where('clockable', true)->where('is_active', true)->count();

            if ($count === 0) {
                $this->warn("  Store {$storeNumber} has no clockable TCP job codes — new hires there will fail JOB_CODE_NOT_MAPPED unless TCP_DEFAULT_JOB_CODE is set.");
            }
        }

        if ($check) {
            $this->info('Check mode — nothing was written.');
        }

        return self::SUCCESS;
    }

    /** The "Restaurant Id" custom field, TCP's store attribution for a job code. */
    private function restaurantId(array $row): ?string
    {
        foreach ((array) ($row['customFields'] ?? []) as $field) {
            if (is_array($field)
                && strcasecmp(trim((string) ($field['description'] ?? '')), 'Restaurant Id') === 0
            ) {
                $value = trim((string) ($field['value'] ?? ''));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
