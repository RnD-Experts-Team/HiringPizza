<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Services\EmployeeWorkflowService;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use Illuminate\Console\Command;

/**
 * Re-emits `hiring.v1.employee.created` for employees that already exist here,
 * so a downstream service can build its replica from nothing.
 *
 * WHY THIS EXISTS
 * ---------------
 * OperationsPizza learns about employees only by consuming our events — it has
 * no employee API and no importer. A brand-new durable consumer created with
 * `--deliver=all` replays everything JetStream still retains, which covers any
 * employee hired since the outbox went in. It cannot invent events for the ones
 * hired before that, and it cannot recover events aged out of the stream. This
 * command manufactures them.
 *
 * WHY `created` AND NOT `updated`
 * -------------------------------
 * `employee.created` carries a FULL snapshot and its handler rebuilds every
 * child collection. `employee.updated` carries deltas and deliberately throws
 * when the employee is not there yet, so it is useless for a backfill.
 *
 * SAFE TO RUN TWICE
 * -----------------
 * Each run mints new event ids, so the consumer's `event_inbox` will not treat
 * them as duplicates and will genuinely reprocess them. That is fine and is the
 * point: the created handler upserts and replaces collections wholesale, so a
 * second run converges on the same state rather than doubling anything.
 *
 * COST
 * ----
 * By default this only writes outbox rows. Publication is left to
 * `outbox:publish-pending`, which is already scheduled every five minutes —
 * that keeps a few thousand employees from turning into a few thousand
 * simultaneous NATS jobs. Use --publish to push them out immediately.
 */
class RepublishEmployeesCommand extends Command
{
    protected $signature = 'hiring:republish-employees
        {--store= : store_number, e.g. 03759-00001; omit for every store}
        {--employee=* : Specific employee ids; repeatable. Overrides --store.}
        {--chunk=100 : Employees loaded per batch}
        {--dry-run : Report what would be published and write nothing}
        {--publish : Dispatch each event immediately instead of leaving it to outbox:publish-pending}';

    protected $description = 'Re-emit employee.created events so a downstream replica can be rebuilt';

    public function handle(
        EmployeeWorkflowService $employees,
        HiringEventFactory $factory,
        HiringOutboxService $outbox,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $publish = (bool) $this->option('publish');
        $chunk = max(1, (int) $this->option('chunk'));

        $ids = array_filter(array_map('intval', (array) $this->option('employee')));
        $storeNumber = $this->option('store');

        if ($ids !== [] && $storeNumber !== null) {
            $this->warn('--employee given, so --store is ignored.');
            $storeNumber = null;
        }

        $query = Employee::query()->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } elseif ($storeNumber !== null) {
            $query->whereHas(
                'stores.store',
                fn ($q) => $q->where('store_number', $storeNumber)
            );
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No employees matched. Nothing to republish.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d employee(s)%s.',
            $dryRun ? 'Would republish' : 'Republishing',
            $total,
            $storeNumber !== null ? " for store {$storeNumber}" : ''
        ));

        // dev_mode flips the subject to hiring.testing.v1.* inside BOTH the
        // factory and the outbox, exactly as EmployeeWorkflowService does. Pass
        // the unprefixed subject to each and let them agree.
        $subject = 'hiring.v1.employee.created';

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $written = 0;
        $storeless = [];

        $query->chunkById($chunk, function ($batch) use (
            $employees, $factory, $outbox, $subject, $dryRun, $publish,
            $bar, &$written, &$storeless
        ) {
            foreach ($batch as $employee) {
                $loaded = $employees->loadEmployee($employee);
                $snapshot = $employees->snapshotEmployee($loaded);

                // `stores` is sorted newest-effective-date first by
                // loadEmployee(), so the first entry is the current store. The
                // consumer derives its real store memberships from
                // data.employee.stores[], not from this field — it is context.
                $currentStore = data_get($snapshot, 'stores.0.store.store_number');

                if ($currentStore === null) {
                    // Replicates with no store links, so it will land inactive
                    // and unschedulable. Worth naming rather than silently
                    // shipping.
                    $storeless[] = $employee->id;
                }

                if ($dryRun) {
                    $bar->advance();

                    continue;
                }

                $envelope = $factory->make($subject, [
                    'employee' => $snapshot,
                    'store_number' => $currentStore,
                ], null, [
                    // Marks these as manufactured, so anyone reading the outbox
                    // or a consumer log later can tell a backfill from a hire.
                    'backfill' => true,
                    'actor_type' => 'service_client',
                ]);

                $row = $outbox->record($subject, $envelope);
                $written++;

                if ($publish) {
                    PublishOutboxEventJob::dispatch($row->id);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($storeless !== []) {
            $this->warn(sprintf(
                '%d employee(s) have no store assignment and will replicate as inactive: %s',
                count($storeless),
                implode(', ', array_slice($storeless, 0, 20)) . (count($storeless) > 20 ? ' …' : '')
            ));
        }

        if ($dryRun) {
            $this->info('Dry run — no outbox rows written.');

            return self::SUCCESS;
        }

        $this->info("Wrote {$written} outbox event(s).");

        if (!$publish) {
            $this->line('They will go out with the next outbox:publish-pending sweep (scheduled every 5 min),');
            $this->line('or run it now:  php artisan outbox:publish-pending');
        }

        return self::SUCCESS;
    }
}
