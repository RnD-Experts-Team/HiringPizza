<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ExternalIdType;
use App\Services\Tcp\TcpEmployeeClientInterface;
use App\Services\Tcp\TcpEmployeeMapper;
use App\Services\Tcp\TcpEmployeeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads TCP Manager+'s employee roster and stores each person's TCP id on the
 * matching HiringPizza employee, as their "TCP ID" id row. Nothing is ever
 * written to TCP: this only issues GETs, and works with TCP_WRITES_ENABLED=false.
 *
 * Matching, in order:
 *   1. exportCode — the field we fill with the employee's Paychex ID (or, when
 *      they have none, our own employee id) when WE push someone to TCP. A
 *      code that is one employee's Paychex ID and another's employee id
 *      matches both, so it is reported as ambiguous rather than guessed.
 *   2. Same first + last name AND (the TCP `location` is one of the employee's
 *      store numbers, OR the email is the same) — for people who were in TCP
 *      before we ever pushed.
 *
 * Only an unambiguous match is saved. Two TCP people for one employee, two
 * employees for one TCP person, or an employee who already carries a different
 * TCP id are all reported and left alone; so is anyone nothing matched.
 *
 * Active people only: only active employees are matched and counted, and TCP
 * people terminated in TCP are skipped. Existing links are respected either way.
 *
 * Operations learns the id from the employee event, not from this table, so run
 * with --republish (or hiring:republish-employees) afterwards.
 */
class LinkTcpEmployeesCommand extends Command
{
    private const LIST_LIMIT = 25;

    protected $signature = 'tcp:link-employees
        {--check : Report the matches without saving anything}
        {--republish : Re-send the newly linked employees to OperationsPizza}
        {--csv= : Also write every name problem to this CSV file, for the people who fix the records}';

    protected $description = 'Match TCP employees to ours and store their TCP ids (read-only against TCP)';

    public function handle(TcpEmployeeClientInterface $tcp, TcpEmployeeSyncService $sync, TcpEmployeeMapper $mapper): int
    {
        $check = (bool) $this->option('check');

        $remote = collect($tcp->listEmployees());

        if ($remote->isEmpty()) {
            $this->warn('TCP returned no employees. Check TCP_DRIVER and the credentials.');

            return self::FAILURE;
        }

        $this->info(sprintf('Fetched %d TCP employee(s).', $remote->count()));

        $all = Employee::query()->with(['contacts', 'stores.store', 'ids.idType', 'statusHistories'])->get();

        // Every stored link counts as taken, active or not — but only ACTIVE
        // employees are matched and reported. An inactive one is never
        // scheduled, so its missing TCP id is not worth chasing.
        $tcpIdOf = $all->mapWithKeys(fn (Employee $e) => [$e->id => $this->tcpIdOf($e)])->filter();
        $holderOf = $tcpIdOf->flip();
        $locals = $all->filter(fn (Employee $e) => $mapper->isActive($e))->values();
        $byName = $locals->groupBy(fn (Employee $e) => $this->name($e->first_name, $e->last_name));
        $terminatedInTcp = 0;

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

            if ($this->terminated($row)) {
                $terminatedInTcp++;

                continue;
            }

            $candidates = $this->candidates($row, $locals, $byName);

            if ($candidates->isEmpty()) {
                $unmatched[] = $row;

                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous[] = [$row, $candidates->map(fn (array $match) => $match[0]->id)->all()];

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
        $toLink = $this->resolveClaims($claims, $ambiguous);

        // Close-name pass, for the same person spelled differently in the two
        // systems ("Hasan Albaker" / "hassan Al Baker"). Only TCP people
        // nothing else matched, only active employees with no TCP id yet,
        // only within the same store, and only when it is one-to-one both
        // ways. Anything less certain is reported, never linked.
        $linkedNow = collect($toLink)->map(fn (array $match) => $match[1]->id)->flip();
        $pool = $locals->filter(fn (Employee $e) => !$tcpIdOf->has($e->id) && !$linkedNow->has($e->id))->values();
        $closeClaims = [];
        $stillUnmatched = [];

        foreach ($unmatched as $row) {
            $close = $pool->filter(fn (Employee $e) => $this->worksAt($e, (string) ($row['location'] ?? ''))
                && $this->closeName($e, $row)
                && !$this->contactsDisagree($e, $row))->values();

            if ($close->count() === 1) {
                $closeClaims[$close->first()->id][] = [$row, $close->first(), 'close-name'];
            } elseif ($close->count() > 1) {
                $ambiguous[] = [$row, $close->pluck('id')->all()];
            } else {
                $stillUnmatched[] = $row;
            }
        }

        $closeLinks = $this->resolveClaims($closeClaims, $ambiguous);
        $toLink = array_merge($toLink, $closeLinks);

        // What is left on each side, for whoever fixes the records.
        $linkedIds = collect($toLink)->map(fn (array $match) => $match[1]->id)->flip();
        $missingFromTcp = $pool->reject(fn (Employee $e) => $linkedIds->has($e->id))->values();

        $inactive = $all->reject(fn (Employee $e) => $mapper->isActive($e))->values();
        $inactiveByName = $inactive->groupBy(fn (Employee $e) => $this->name($e->first_name, $e->last_name));
        $inactiveHere = [];
        $unmatched = [];

        foreach ($stillUnmatched as $row) {
            $former = $this->candidates($row, $inactive, $inactiveByName);

            if ($former->isNotEmpty()) {
                $inactiveHere[] = [$row, $former->first()[0]];
            } else {
                $unmatched[] = $row;
            }
        }

        if (!$check) {
            foreach ($toLink as [$row, $employee]) {
                $sync->storeTcpId($employee, (string) $row['employeeId']);
            }
        }

        $byMethod = collect($toLink)->countBy(fn ($match) => $match[2]);

        $this->table(['metric', 'count'], [
            ['TCP people already linked to an employee', $alreadyLinked],
            ['TCP people terminated in TCP (skipped)', $terminatedInTcp],
            [$check ? 'would link by exportCode' : 'linked by exportCode', $byMethod['exportCode'] ?? 0],
            [$check ? 'would link by name + store/email' : 'linked by name + store/email', $byMethod['name'] ?? 0],
            [$check ? 'would link by close name, same store' : 'linked by close name, same store', $byMethod['close-name'] ?? 0],
            ['ambiguous (not linked)', count($ambiguous)],
            ['employee already has a different TCP id (not linked)', count($conflicts)],
            ['active in TCP but inactive in HiringPizza', count($inactiveHere)],
            ['active in TCP, not in HiringPizza at all', count($unmatched)],
            ['active employees still without a TCP id', $missingFromTcp->count()],
        ]);

        $this->listRows('Spelled differently in HiringPizza and TCP (' . ($check ? 'would link' : 'linked') . ', fix the spelling)', collect($closeLinks),
            fn ($m) => sprintf('employee %d %s (%s) = %s', $m[1]->id, $this->fullName($m[1]), $this->storesOf($m[1]), $this->describe($m[0])));
        $this->listRows('Ambiguous', collect($ambiguous), fn ($m) => $this->describe($m[0]) . ' -> employee ids ' . implode(', ', $m[1]));
        $this->listRows('Different TCP id already stored', collect($conflicts), fn ($m) => $this->describe($m[0]) . " vs employee {$m[1]->id} who has TCP {$m[2]}");
        $this->listRows('Active in TCP but inactive in HiringPizza (terminate in TCP, or rehire here)', collect($inactiveHere),
            fn ($m) => $this->describe($m[0]) . " = employee {$m[1]->id} {$this->fullName($m[1])}");
        $this->listRows('Active in TCP, not in HiringPizza at all', collect($unmatched), fn ($row) => $this->describe($row));
        $this->listRows('Active employees with no TCP match (not in TCP, or too different to match)', $missingFromTcp,
            fn (Employee $e) => sprintf('employee %d %s (%s)', $e->id, $this->fullName($e), $this->storesOf($e)));

        if (filled($this->option('csv'))) {
            $this->writeCsv((string) $this->option('csv'), $closeLinks, $ambiguous, $conflicts, $inactiveHere, $unmatched, $missingFromTcp);
        }

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

        if ($exportCode !== '') {
            $byExportCode = $locals
                ->filter(fn (Employee $e) => $this->paychexIdOf($e) === $exportCode
                    || (ctype_digit($exportCode) && $e->id === (int) $exportCode))
                ->map(fn (Employee $e) => [$e, 'exportCode'])
                ->values();

            if ($byExportCode->isNotEmpty()) {
                return $byExportCode;
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

    /**
     * Keep the claims that are one-to-one; an employee claimed by two TCP
     * people is not safe to pick, so those go to $ambiguous.
     *
     * @param  array<int, array<int, array{0: array, 1: Employee, 2: string}>>  $claims
     * @return array<int, array{0: array, 1: Employee, 2: string}>
     */
    private function resolveClaims(array $claims, array &$ambiguous): array
    {
        $links = [];

        foreach ($claims as $employeeId => $list) {
            if (count($list) > 1) {
                foreach ($list as [$row]) {
                    $ambiguous[] = [$row, [$employeeId]];
                }

                continue;
            }

            $links[] = $list[0];
        }

        return $links;
    }

    private function worksAt(Employee $employee, string $storeNumber): bool
    {
        return $storeNumber !== '' && $employee->stores->contains(fn ($s) => $s->store?->store_number === $storeNumber);
    }

    /**
     * The same person spelled differently: the first names are close, and
     * some later part of one name is close to some later part of the other.
     * So a middle name, a second surname or "Jr" on one side only still
     * matches ("Mark Green" / "MARK ALLEN THOMAS GREEN"), and so does a
     * surname split in two ("Albaker" / "Al Baker").
     *
     * Names alone cannot tell two relatives apart ("Fadi" / "Hadi"), so a
     * close name is refused when the two records carry different emails, or
     * different phone numbers.
     */
    private function closeName(Employee $employee, array $row): bool
    {
        $ours = $this->nameTokens($employee->first_name . ' ' . $employee->last_name);
        $theirs = $this->nameTokens(($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? ''));

        if (count($ours) < 2 || count($theirs) < 2 || !$this->similar($ours[0], $theirs[0])) {
            return false;
        }

        $ourRest = array_slice($ours, 1);
        $theirRest = array_slice($theirs, 1);

        if ($this->similar(implode('', $ourRest), implode('', $theirRest))) {
            return true;
        }

        foreach ($ourRest as $a) {
            foreach ($theirRest as $b) {
                if ($this->similar($a, $b)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Both records have an email and they differ, or both have a phone and they differ. */
    private function contactsDisagree(Employee $employee, array $row): bool
    {
        $ours = fn (string $type) => $employee->contacts
            ->filter(fn ($c) => strtolower((string) ($c->contact_type->value ?? $c->contact_type)) === $type)
            ->map(fn ($c) => (string) $c->contact_value);

        $emails = $ours('email')->map(fn ($v) => Str::lower(trim($v)))->filter()->values();
        $theirEmail = Str::lower(trim((string) ($row['email'] ?? '')));

        if ($theirEmail !== '' && $emails->isNotEmpty() && !$emails->contains($theirEmail)) {
            return true;
        }

        $digits = fn (string $v) => substr((string) preg_replace('/\D+/', '', $v), -10);
        $phones = $ours('phone')->map($digits)->filter()->values();
        $theirPhone = $digits((string) ($row['cell'] ?? ''));

        return $theirPhone !== '' && $phones->isNotEmpty() && !$phones->contains($theirPhone);
    }

    /** @return array<int, string> lower-case ASCII words, without suffixes or initials */
    private function nameTokens(string $name): array
    {
        $words = preg_split('/[^a-z]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn (string $w) => strlen($w) > 1 && !in_array($w, ['jr', 'sr', 'ii', 'iii', 'iv'], true)));
    }

    /** Equal, or a typo apart: one edit for short words, two from five letters up. */
    private function similar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (min(strlen($a), strlen($b)) < 3) {
            return false;
        }

        return levenshtein($a, $b) <= (max(strlen($a), strlen($b)) <= 4 ? 1 : 2);
    }

    private function fullName(Employee $employee): string
    {
        return trim($employee->first_name . ' ' . $employee->last_name);
    }

    private function storesOf(Employee $employee): string
    {
        return $employee->stores->map(fn ($s) => $s->store?->store_number)->filter()->unique()->implode('/') ?: 'no store';
    }

    private function writeCsv(string $path, array $closeLinks, array $ambiguous, array $conflicts, array $inactiveHere, array $unmatched, Collection $missingFromTcp): void
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error("Could not write {$path}.");

            return;
        }

        $tcpName = fn (array $row) => trim(($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? ''));
        fputcsv($handle, ['issue', 'employee_id', 'hiringpizza_name', 'hiringpizza_store', 'tcp_id', 'tcp_name', 'tcp_location', 'what_to_do']);

        foreach ($closeLinks as [$row, $employee]) {
            fputcsv($handle, ['name spelled differently', $employee->id, $this->fullName($employee), $this->storesOf($employee), $row['employeeId'] ?? '', $tcpName($row), $row['location'] ?? '', 'Linked automatically. Correct the spelling in whichever system is wrong.']);
        }

        foreach ($missingFromTcp as $employee) {
            fputcsv($handle, ['active here, no TCP match', $employee->id, $this->fullName($employee), $this->storesOf($employee), '', '', '', 'Not in TCP, or named too differently to match. Push them (tcp:push-employee) or set their TCP ID.']);
        }

        foreach ($inactiveHere as [$row, $employee]) {
            fputcsv($handle, ['active in TCP, inactive here', $employee->id, $this->fullName($employee), $this->storesOf($employee), $row['employeeId'] ?? '', $tcpName($row), $row['location'] ?? '', 'Terminate them in TCP, or rehire them here if they still work.']);
        }

        foreach ($unmatched as $row) {
            fputcsv($handle, ['active in TCP, not in HiringPizza', '', '', '', $row['employeeId'] ?? '', $tcpName($row), $row['location'] ?? '', 'Add them in HiringPizza if they work here; otherwise terminate them in TCP.']);
        }

        foreach ($ambiguous as [$row, $ids]) {
            fputcsv($handle, ['ambiguous', implode(' ', $ids), '', '', $row['employeeId'] ?? '', $tcpName($row), $row['location'] ?? '', 'Duplicate person or name clash. Decide which record is right and set the TCP ID by hand.']);
        }

        foreach ($conflicts as [$row, $employee, $stored]) {
            fputcsv($handle, ['different TCP id stored', $employee->id, $this->fullName($employee), $this->storesOf($employee), $row['employeeId'] ?? '', $tcpName($row), $row['location'] ?? '', "Employee already has TCP {$stored}. One of the two TCP records is a duplicate; terminate it."]);
        }

        fclose($handle);
        $this->info("Wrote the issue list to {$path}.");
    }

    private function tcpIdOf(Employee $employee): ?string
    {
        $row = $employee->ids->first(fn ($id) => $id->idType?->label === ExternalIdType::TCP && filled($id->id_value));

        return $row === null ? null : (string) $row->id_value;
    }

    private function paychexIdOf(Employee $employee): ?string
    {
        $row = $employee->ids->first(fn ($id) => $id->idType?->label === ExternalIdType::PAYCHEX && filled($id->id_value));

        return $row === null ? null : trim((string) $row->id_value);
    }

    /** A TCP record whose terminationDate is today or earlier. */
    private function terminated(array $row): bool
    {
        $date = $row['terminationDate'] ?? null;

        if (blank($date)) {
            return false;
        }

        try {
            return Carbon::parse((string) $date)->startOfDay()->lte(today());
        } catch (\Throwable) {
            return false;
        }
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
