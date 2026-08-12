<?php

namespace App\Console\Commands;

use App\Services\Humanity\HumanityStaffClientInterface;
use Illuminate\Console\Command;

/**
 * Read-only. Answers the one question blocking the whole employee design:
 * which field does TCP's TimeClock Plus connector use to match employees
 * between the two systems?
 *
 * It matters because `eid` is Humanity's standard external-id field, and it is
 * exactly what HumanityEmployeeMapper and humanity:backfill-employee-ids write
 * with OUR employee id. If TCP already owns that field, writing it would break
 * TCP's production sync — so this command looks before anything touches it.
 */
class InspectHumanityEmployeesCommand extends Command
{
    protected $signature = 'humanity:inspect-employees
        {--limit=10 : How many employees to show}
        {--raw : Dump the full raw record of the first employee}';

    protected $description = 'Inspect Humanity employee records to see which external id fields TCP populates';

    /** Fields any of the systems might be using to cross-reference a person. */
    private const ID_FIELDS = [
        'eid', 'employee_id', 'external_id', 'badge', 'badge_number',
        'payroll_id', 'custom_id', 'employee_number', 'tcp_id',
    ];

    public function handle(HumanityStaffClientInterface $client): int
    {
        if (config('humanity.driver') !== 'http') {
            $this->warn('HUMANITY_DRIVER is not "http" — this will read the in-memory fake, not real data.');
            $this->newLine();
        }

        $employees = $client->listEmployees(includeInactive: true);

        if ($employees === []) {
            $this->error('Humanity returned no employees.');

            return self::FAILURE;
        }

        $this->info(sprintf('Humanity roster: %d employee(s).', count($employees)));
        $this->newLine();

        if ($this->option('raw')) {
            $this->line(json_encode($employees[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sample = array_slice($employees, 0, $limit);

        // Only show id fields that are actually present, or the table is mostly
        // empty columns.
        $present = array_values(array_filter(
            self::ID_FIELDS,
            fn (string $field) => $this->anyPopulated($employees, $field)
        ));

        $this->table(
            array_merge(['id', 'name', 'email'], $present),
            array_map(function (array $employee) use ($present) {
                $row = [
                    $this->show($employee, 'id'),
                    $this->show($employee, 'name'),
                    $this->show($employee, 'email'),
                ];

                foreach ($present as $field) {
                    $row[] = $this->show($employee, $field);
                }

                return $row;
            }, $sample)
        );

        $this->verdict($employees, $present);

        return self::SUCCESS;
    }

    private function verdict(array $employees, array $present): void
    {
        $this->newLine();
        $eidPopulated = $this->populatedCount($employees, 'eid');
        $total = count($employees);

        if ($eidPopulated === 0) {
            $this->info('`eid` is EMPTY on every record.');
            $this->line('  TCP is matching on something else, so `eid` looks free for us to use.');
            $this->line('  Cross-check against a TCP employee record before trusting this — an');
            $this->line('  empty field is weaker evidence than seeing TCP\'s own id somewhere else.');

            if ($present !== []) {
                $this->line('  Populated id-ish fields to compare with TCP: ' . implode(', ', $present));
            }

            return;
        }

        $this->warn(sprintf('`eid` is POPULATED on %d of %d records.', $eidPopulated, $total));
        $this->newLine();
        $this->line('  Check whether those values are TCP employee ids. If they are, TCP matches');
        $this->line('  on `eid` and we must NOT write it — employees have to be pushed to');
        $this->line('  TCP Manager+ instead, and the Humanity employee push retired.');
        $this->newLine();
        $this->line('  Keep TCP_CONNECTOR_ACTIVE=true until that is settled.');
    }

    private function anyPopulated(array $employees, string $field): bool
    {
        return $this->populatedCount($employees, $field) > 0;
    }

    private function populatedCount(array $employees, string $field): int
    {
        $count = 0;

        foreach ($employees as $employee) {
            $value = $employee[$field] ?? null;

            if ($value !== null && $value !== '' && !is_array($value)) {
                $count++;
            }
        }

        return $count;
    }

    private function show(array $employee, string $field): string
    {
        $value = $employee[$field] ?? null;

        if ($value === null || $value === '' || is_array($value)) {
            return '—';
        }

        return (string) $value;
    }
}
