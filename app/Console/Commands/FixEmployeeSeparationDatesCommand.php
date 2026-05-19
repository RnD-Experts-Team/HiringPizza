<?php

namespace App\Console\Commands;

use App\Services\EmployeeSeparationDateFixService;
use Illuminate\Console\Command;

class FixEmployeeSeparationDatesCommand extends Command
{
    protected $signature = 'employees:fix-separation-dates
        {path : Absolute or relative path to the CSV file}
        {--dry-run : Validate and map rows without writing to the database}
        {--allow-name-only : Allow matching by name only when SSN and birth date are missing}';

    protected $description = 'Fix termination/resignation effective dates by updating the latest status history from a CSV.';

    public function handle(EmployeeSeparationDateFixService $service): int
    {
        $path = (string) $this->argument('path');
        $resolvedPath = $this->resolvePath($path);

        $this->info('Starting separation date fix...');
        $this->line('File: ' . $resolvedPath);
        $this->line('Mode: ' . ($this->option('dry-run') ? 'dry-run' : 'update'));

        $result = $service->apply($resolvedPath, [
            'dry_run' => (bool) $this->option('dry-run'),
            'allow_name_only' => (bool) $this->option('allow-name-only'),
        ]);

        $summary = $result['summary'];
        $this->newLine();
        $this->info('Summary:');
        $this->line('Rows:          ' . $summary['rows_total']);
        $this->line('Updated:       ' . $summary['updated']);
        $this->line('Skipped:       ' . $summary['skipped']);
        $this->line('Not found:     ' . $summary['not_found']);
        $this->line('Missing date:  ' . $summary['missing_date']);
        $this->line('Status skip:   ' . $summary['status_mismatch']);
        $this->line('Failed:        ' . $summary['failed']);

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings (first 20):');
            foreach (array_slice($result['warnings'], 0, 20) as $warning) {
                $this->line('Line ' . $warning['line'] . ': ' . $warning['warning']);
            }

            if (count($result['warnings']) > 20) {
                $this->line('... and ' . (count($result['warnings']) - 20) . ' more warnings.');
            }
        }

        if ($result['failures'] !== []) {
            $this->newLine();
            $this->error('Failures (first 20):');
            foreach (array_slice($result['failures'], 0, 20) as $failure) {
                $this->line('Line ' . $failure['line'] . ': ' . $failure['error']);
            }

            if (count($result['failures']) > 20) {
                $this->line('... and ' . (count($result['failures']) - 20) . ' more failures.');
            }

            return self::FAILURE;
        }

        $this->info('Separation date fix completed successfully.');

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
