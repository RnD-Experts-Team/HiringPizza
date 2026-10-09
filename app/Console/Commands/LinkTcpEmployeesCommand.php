<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ExternalIdType;
use App\Services\Tcp\TcpEmployeeClientInterface;
use App\Services\Tcp\TcpEmployeeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads TCP Manager+'s employee roster and stores each person's TCP id on the
 * matching HiringPizza employee, as their "TCP ID" id row. Nothing is ever
 * written to TCP: this only issues GETs, and works with TCP_WRITES_ENABLED=false.
 *
 * Matching, in order:
 *   1. exportCode — the field we fill with our own employee id when WE create
 *      someone in TCP.
 *   2. Same first + last name AND (the TCP `location` is one of the employee's
 *      store numbers, OR the email is the same) — for people who were in TCP
 *      before we ever pushed.
 *
 * Only an unambiguous match is saved. Two TCP people for one employee, two
 * employees for one TCP person, or an employee who already carries a different
 * TCP id are all reported and left alone; so is anyone nothing matched.
 *
 * Operations learns the id from the employee event, not from this table, so run
 * with --republish (or hiring:republish-employees) afterwards.
 */
class LinkTcpEmployeesCommand extends Command
{
    private const LIST_LIMIT = 25;

    protected $signature = 'tcp:link-employees
        {--check : Report the matches without saving anything}
        {--republish : Re-send the newly linked employees to OperationsPizza}';

    protected $description = 'Match TCP employees to ours and store their TCP ids (read-only against TCP)';

    public function handle(TcpEmployeeClientInterface $tcp, TcpEmployeeSyncService $sync): int
    {
        $check = (bool) $this->option('check');

        $remote = collect($tcp->listEmployees());

        if ($remote->isEmpty()) {
            $this->warn('TCP returned no employees. Check TCP_DRIVER and the credentials.');

            return self::FAILURE;
        }

        $this->info(sprintf('Fetched %d TCP employee(s).', $remote->count()));

        $locals = Employee::query()->with(['contacts', 'stores.store', 'ids.idType'])->get();

        $tcpIdOf = $locals->mapWithKeys(fn (Employee $e) => [$e->id => $this->tcpIdOf($e)])->filter();
        $holderOf = $tcpIdOf->flip();
        $byName = $locals->groupBy(fn (Employee $e) => $this->name($e->first_name, $e->last_name));

        $claims = [];
        $alreadyLinked = 0;
        $conflicts = [];
        $unmatched = [];
        $ambiguous = [];

        foreach ($remote as $row) {
            $tcpId = (string) ($row['employeeId'] ?? '');

            if ($tcpId === '') {
                continue;
            }

            if ($holderOf->has($tcpId)) {
                $alreadyLinked++;

                continue;
            }

            $candidates = $this->candidates($row, $locals, $byName);

            if ($candidates->isEmpty()) {
                $unmatched[] = $row;

                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous[] = [$row, $candidates->pluck('id')->all()];

                continue;
            }

            [$employee, $method] = $candidates->first();

            if ($tcpIdOf->has($employee->id)) {
                $conflicts[] = [$row, $employee, $tcpIdOf[$employee->id]];

                continue;
            }

            $claims[$employee->id][] = [$row, $employee, $method];
        }

        // Two TCP people claiming one employee: neither is safe to pick.
        $toLink = [];

        foreach ($claims as $employeeId => $list) {
            if (count($list) > 1) {
                foreach ($list as [$row]) {
                    $ambiguous[] = [$row, [$employeeId]];
                }

                continue;
            }

            $toLink[] = $list[0];
        }

        if (!$check) {
            foreach ($toLink as [$row, $employee]) {
                $sync->storeTcpId($employee, (string) $row['employeeId']);
            }
        }

        $byMethod = collect($toLink)->countBy(fn ($match) => $match[2]);

        $this->table(['metric', 'count'], [
            ['TCP people already linked to an employee', $alreadyLinked],
            [$check ? 'would link by exportCode' : 'linked by exportCode', $byMethod['exportCode'] ?? 0],
            [$check ? 'would link by name + store/email' : 'linked by name + store/email', $byMethod['name'] ?? 0],
            ['ambiguous (not linked)', count($ambiguous)],
            ['employee already has a different TCP id (not linked)', count($conflicts)],
            ['TCP people nothing matched', count($unmatched)],
            ['our employees still without a TCP id', $locals->count() - $tcpIdOf->count() - count($toLink)],
        ]);

        $this->listRows('Ambiguous', collect($ambiguous), fn ($m) => $this->describe($m[0]) . ' -> employee ids ' . implode(', ', $m[1]));
        $this->listRows('Different TCP id already stored', collect($conflicts), fn ($m) => $this->describe($m[0]) . " vs employee {$m[1]->id} who has TCP {$m[2]}");
        $this->listRows('No match in our employees', collect($unmatched), fn ($row) => $this->describe($row));

        if ($check) {
            $this->info('Check mode — nothing was written.');

            return self::SUCCESS;
        }

        if ($this->option('republish') && $toLink !== []) {
            $this->call('hiring:republish-employees', [
                '--employee' => collect($toLink)->map(fn ($match) => $match[1]->id)->all(),
            ]);
        } elseif ($toLink !== []) {
            $this->line('Operations has not been told yet: re-run with --republish, or run hiring:republish-employees.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{0: Employee, 1: string}> employee + how it matched
     */
    private function candidates(array $row, Collection $locals, Collection $byName): Collection
    {
        $exportCode = (string) ($row['exportCode'] ?? '');

        if ($exportCode !== '' && ctype_digit($exportCode)) {
            $employee = $locals->firstWhere('id', (int) $exportCode);

            if ($employee !== null) {
                return collect([[$employee, 'exportCode']]);
            }
        }

        $location = (string) ($row['location'] ?? '');
        $email = Str::lower(trim((string) ($row['email'] ?? '')));

        return ($byName[$this->name($row['firstName'] ?? '', $row['lastName'] ?? '')] ?? collect())
            ->filter(fn (Employee $e) => ($location !== '' && $e->stores->contains(fn ($s) => $s->store?->store_number === $location))
                || ($email !== '' && $e->contacts->contains(fn ($c) => strtolower((string) ($c->contact_type->value ?? $c->contact_type)) === 'email'
                    && Str::lower(trim($c->contact_value)) === $email)))
            ->map(fn (Employee $e) => [$e, 'name'])
            ->values();
    }

    private function tcpIdOf(Employee $employee): ?string
    {
        $row = $employee->ids->first(fn ($id) => $id->idType?->label === ExternalIdType::TCP && filled($id->id_value));

        return $row === null ? null : (string) $row->id_value;
    }

    private function name(?string $first, ?string $last): string
    {
        $clean = fn (?string $part) => trim(preg_replace('/[^a-z ]+/', '', Str::lower(Str::ascii((string) $part))));

        return $clean($first) . ' ' . $clean($last);
    }

    private function describe(array $row): string
    {
        return sprintf(
            'TCP %s %s %s (%s)',
            $row['employeeId'] ?? '?',
            $row['firstName'] ?? '',
            $row['lastName'] ?? '',
            $row['location'] ?? 'no location'
        );
    }

    private function listRows(string $title, Collection $rows, callable $line): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn("{$title}: {$rows->count()}");

        $shown = $this->output->isVerbose() ? $rows : $rows->take(self::LIST_LIMIT);

        foreach ($shown as $row) {
            $this->line('  ' . $line($row));
        }

        if ($shown->count() < $rows->count()) {
            $this->line(sprintf('  … and %d more (run with -v to list them all).', $rows->count() - $shown->count()));
        }
    }
}
