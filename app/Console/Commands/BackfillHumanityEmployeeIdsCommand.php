<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Humanity\HumanityEmployeeMapper;
use App\Services\Humanity\HumanityEmployeeSyncService;
use App\Services\Humanity\HumanityStaffClientInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Matches the roster that already exists in Humanity to our employees.
 *
 * THIS MUST RUN BEFORE HUMANITY_WRITES_ENABLED IS TURNED ON.
 *
 * Humanity has been in production for a while and every current employee was
 * created there by hand, with no `eid`. HumanityEmployeeSyncService looks
 * employees up by eid, so without this backfill it would miss on every single
 * person and create a SECOND staff record for the entire roster — visible to
 * managers, assignable to shifts, and painful to unpick given Humanity has no
 * bulk delete.
 *
 * Matching is deliberately conservative: exact email first, then normalised
 * name, and anything ambiguous is reported for a human rather than guessed.
 */
class BackfillHumanityEmployeeIdsCommand extends Command
{
    protected $signature = 'humanity:backfill-employee-ids
        {--apply : Write the eid to Humanity and the id to employee_ids. Without this it is a dry run.}
        {--csv= : Write the full match report to this path}';

    protected $description = 'Match existing Humanity staff to our employees and record the link';

    public function handle(
        HumanityStaffClientInterface $client,
        HumanityEmployeeSyncService $sync,
        HumanityEmployeeMapper $mapper,
    ): int {
        $apply = (bool) $this->option('apply');

        $this->info($apply ? 'APPLYING matches.' : 'Dry run — nothing will be written. Use --apply once the report looks right.');

        $remote = collect($client->listEmployees(includeInactive: true));
        $this->info("Humanity roster: {$remote->count()} record(s).");

        $local = Employee::query()
            ->with(['contacts', 'ids.idType', 'statusHistories'])
            ->get();
        $this->info("Local employees: {$local->count()}.");

        $remoteByEid = $this->indexByEid($remote);
        $remoteByEmail = $this->indexByEmail($remote);
        $remoteByName = $this->indexByName($remote);

        $matched = [];
        $ambiguous = [];
        $unmatchedLocal = [];
        $claimedRemoteIds = [];

        foreach ($local as $employee) {
            // Already linked, either from a previous run or a prior create.
            $existing = $remoteByEid[(string) $employee->id] ?? null;

            if ($existing !== null) {
                $matched[] = ['employee' => $employee, 'remote' => $existing, 'via' => 'eid'];
                $claimedRemoteIds[] = $this->remoteId($existing);

                continue;
            }

            $email = $this->primaryEmail($employee);

            if ($email !== null && isset($remoteByEmail[$email])) {
                $candidates = $remoteByEmail[$email];

                if (count($candidates) === 1) {
                    $matched[] = ['employee' => $employee, 'remote' => $candidates[0], 'via' => 'email'];
                    $claimedRemoteIds[] = $this->remoteId($candidates[0]);

                    continue;
                }

                $ambiguous[] = ['employee' => $employee, 'candidates' => $candidates, 'via' => 'email'];

                continue;
            }

            $nameKey = $this->nameKey($employee->first_name, $employee->last_name);
            $candidates = $remoteByName[$nameKey] ?? [];

            if (count($candidates) === 1) {
                $matched[] = ['employee' => $employee, 'remote' => $candidates[0], 'via' => 'name'];
                $claimedRemoteIds[] = $this->remoteId($candidates[0]);

                continue;
            }

            if (count($candidates) > 1) {
                // Never auto-resolve: picking the wrong "John Smith" links a
                // real person's schedule to somebody else.
                $ambiguous[] = ['employee' => $employee, 'candidates' => $candidates, 'via' => 'name'];

                continue;
            }

            $unmatchedLocal[] = $employee;
        }

        $unmatchedRemote = $remote->reject(
            fn (array $row) => in_array($this->remoteId($row), $claimedRemoteIds, true)
        )->values();

        $this->table(['bucket', 'count'], [
            ['matched', count($matched)],
            ['ambiguous (needs a human)', count($ambiguous)],
            ['in hiring, not in Humanity', count($unmatchedLocal)],
            ['in Humanity, not in hiring', $unmatchedRemote->count()],
        ]);

        foreach ($ambiguous as $row) {
            $this->warn(sprintf(
                '  AMBIGUOUS %s (%s) matched %d Humanity records by %s: %s',
                trim("{$row['employee']->first_name} {$row['employee']->last_name}"),
                $row['employee']->id,
                count($row['candidates']),
                $row['via'],
                implode(', ', array_map(fn ($c) => $this->remoteId($c), $row['candidates']))
            ));
        }

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $matched, $ambiguous, $unmatchedLocal, $unmatchedRemote);
            $this->info("Report written to {$path}");
        }

        if (!$apply) {
            $this->comment('Dry run complete. Re-run with --apply to write the links.');

            return self::SUCCESS;
        }

        if (!config('humanity.writes_enabled')) {
            $this->error('HUMANITY_WRITES_ENABLED is false — cannot stamp eids. Enable it for this run only.');

            return self::FAILURE;
        }

        $applied = 0;

        foreach ($matched as $row) {
            $employee = $row['employee'];
            $humanityId = $this->remoteId($row['remote']);

            try {
                if ($row['via'] !== 'eid') {
                    // Stamp our id onto their record so every later lookup is
                    // an exact eid hit rather than a fuzzy match.
                    $client->updateEmployee($humanityId, ['eid' => (string) $employee->id]);
                }

                $sync->storeHumanityId($employee, $humanityId);
                $applied++;
            } catch (\Throwable $e) {
                $this->error("  Failed to link employee {$employee->id} → {$humanityId}: {$e->getMessage()}");
            }
        }

        $this->info("Linked {$applied} employee(s).");

        if ($ambiguous !== [] || $unmatchedLocal !== []) {
            $this->warn('Unresolved records remain. Do NOT leave HUMANITY_WRITES_ENABLED on for normal traffic');
            $this->warn('until they are resolved, or those employees will be created as duplicates.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function indexByEid(Collection $remote): array
    {
        $index = [];

        foreach ($remote as $row) {
            $eid = $this->trimmed($row['eid'] ?? null);

            if ($eid !== null) {
                $index[$eid] = $row;
            }
        }

        return $index;
    }

    private function indexByEmail(Collection $remote): array
    {
        $index = [];

        foreach ($remote as $row) {
            $email = $this->trimmed($row['email'] ?? null);

            if ($email !== null) {
                $index[strtolower($email)][] = $row;
            }
        }

        return $index;
    }

    private function indexByName(Collection $remote): array
    {
        $index = [];

        foreach ($remote as $row) {
            $first = $row['fname'] ?? $row['first_name'] ?? null;
            $last = $row['lname'] ?? $row['last_name'] ?? null;

            if ($first === null && $last === null && isset($row['name'])) {
                $parts = preg_split('/\s+/', trim((string) $row['name'])) ?: [];
                $first = $parts[0] ?? null;
                $last = count($parts) > 1 ? end($parts) : null;
            }

            $key = $this->nameKey((string) $first, (string) $last);

            if ($key !== '') {
                $index[$key][] = $row;
            }
        }

        return $index;
    }

    private function nameKey(?string $first, ?string $last): string
    {
        $normalize = fn (?string $value) => preg_replace('/[^a-z]/', '', strtolower((string) $value));

        return $normalize($first) . '|' . $normalize($last);
    }

    private function primaryEmail(Employee $employee): ?string
    {
        $contact = $employee->contacts
            ->filter(fn ($row) => strtolower((string) ($row->contact_type->value ?? $row->contact_type)) === 'email')
            ->sortByDesc('is_primary')
            ->first();

        $value = $this->trimmed($contact?->contact_value);

        return $value === null ? null : strtolower($value);
    }

    private function remoteId(array $row): string
    {
        foreach (['id', 'employee_id', 'user_id'] as $key) {
            $value = $this->trimmed($row[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    private function trimmed(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function writeCsv(string $path, array $matched, array $ambiguous, array $unmatchedLocal, Collection $unmatchedRemote): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['bucket', 'employee_id', 'employee_name', 'employee_email', 'humanity_id', 'matched_via', 'detail']);

        foreach ($matched as $row) {
            fputcsv($handle, [
                'matched',
                $row['employee']->id,
                trim("{$row['employee']->first_name} {$row['employee']->last_name}"),
                $this->primaryEmail($row['employee']),
                $this->remoteId($row['remote']),
                $row['via'],
                '',
            ]);
        }

        foreach ($ambiguous as $row) {
            fputcsv($handle, [
                'ambiguous',
                $row['employee']->id,
                trim("{$row['employee']->first_name} {$row['employee']->last_name}"),
                $this->primaryEmail($row['employee']),
                '',
                $row['via'],
                implode(' ', array_map(fn ($c) => $this->remoteId($c), $row['candidates'])),
            ]);
        }

        foreach ($unmatchedLocal as $employee) {
            fputcsv($handle, [
                'not_in_humanity',
                $employee->id,
                trim("{$employee->first_name} {$employee->last_name}"),
                $this->primaryEmail($employee),
                '', '', '',
            ]);
        }

        foreach ($unmatchedRemote as $row) {
            fputcsv($handle, [
                'not_in_hiring', '', $row['name'] ?? '', $row['email'] ?? '',
                $this->remoteId($row), '', '',
            ]);
        }

        fclose($handle);
    }
}
