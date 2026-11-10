<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\StorePayrollWeek;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use SplFileObject;
use Throwable;

/**
 * One-time load of the weekly payroll sheet (one row per store and Tue–Mon
 * week) for the weeks before employee metrics were imported. Takes the sheet
 * saved as CSV with its own headers and reads only Start Date, Franchise-Number,
 * Total payroll and Payroll to Sales. Running it again replaces the same
 * store-weeks.
 */
class ImportPayrollWeeksCommand extends Command
{
    protected $signature = 'hiring:import-payroll-weeks
        {path : The payroll sheet saved as CSV}
        {--dry-run : Read and check every row without writing}';

    protected $description = 'Load weekly store total payroll and payroll-to-sales % (weeks before employee metrics) from a CSV.';

    /** Normalised header → column. */
    private const HEADERS = [
        'franchise_number' => 'store', 'store' => 'store', 'store_number' => 'store',
        'sart_date' => 'week_start', 'start_date' => 'week_start', 'week_start' => 'week_start',
        'total_payroll' => 'total_payroll',
        'payroll_to_sales' => 'payroll_to_sales',
    ];

    private const REQUIRED = ['store', 'week_start', 'total_payroll', 'payroll_to_sales'];

    private const TUESDAY = 2;

    private const WEEK_DAYS = 6;

    private const PERCENT = 100;

    private const LIST_SIZE = 20;

    private const ROWS_PER_QUERY = 500;

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (!is_file($path)) {
            $this->error("No file at {$path}");

            return self::FAILURE;
        }

        $csv = new SplFileObject($path);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $columns = array_map(
            fn ($header) => self::HEADERS[$this->normalise((string) $header)] ?? null,
            (array) $csv->fgetcsv(),
        );
        $missing = array_diff(self::REQUIRED, $columns);
        if ($missing !== []) {
            $this->error('Missing columns: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $stores = Store::query()->pluck('id', 'store_number');
        $rows = [];
        $problems = [];

        foreach ($csv as $index => $values) {
            // foreach rewinds the file: line 1 is the header again.
            if ($index === 0) {
                continue;
            }
            $line = $index + 1;
            $row = [];
            foreach ($columns as $i => $column) {
                if ($column !== null) {
                    $row[$column] = trim((string) ($values[$i] ?? ''));
                }
            }
            if (($row['store'] ?? '') === '' && ($row['week_start'] ?? '') === '') {
                continue;
            }

            $storeId = $stores[$row['store']] ?? null;
            $weekStart = $this->date($row['week_start'] ?? '');
            $problem = match (true) {
                $storeId === null => "unknown store \"{$row['store']}\"",
                $weekStart === null => "bad start date \"{$row['week_start']}\"",
                $weekStart->dayOfWeek !== self::TUESDAY => "{$weekStart->toDateString()} is not a Tuesday",
                default => null,
            };
            if ($problem !== null) {
                $problems[] = "Line {$line}: {$problem}";

                continue;
            }

            $ratio = $this->number($row['payroll_to_sales'] ?? '');
            // A later row for the same store-week wins.
            $rows[$storeId.'|'.$weekStart->toDateString()] = [
                'store_id' => $storeId,
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekStart->addDays(self::WEEK_DAYS)->toDateString(),
                'total_payroll' => $this->number($row['total_payroll'] ?? ''),
                'labor_percent' => $ratio !== null ? round($ratio * self::PERCENT, 4) : null,
            ];
        }

        if (!$this->option('dry-run')) {
            foreach (array_chunk(array_values($rows), self::ROWS_PER_QUERY) as $chunk) {
                StorePayrollWeek::query()->upsert($chunk, ['store_id', 'week_start'], ['week_end', 'total_payroll', 'labor_percent']);
            }
        }

        $this->info(($this->option('dry-run') ? 'Would save' : 'Saved').' '.count($rows).' store-weeks.');
        if ($problems !== []) {
            $this->warn(count($problems).' rows skipped:');
            foreach (array_slice($problems, 0, self::LIST_SIZE) as $problem) {
                $this->line("  {$problem}");
            }
        }

        return self::SUCCESS;
    }

    private function normalise(string $header): string
    {
        $header = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))), '_');
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function number(string $value): ?float
    {
        $clean = str_replace(['$', ',', ' ', '%'], '', $value);

        return is_numeric($clean) ? (float) $clean : null;
    }
}
