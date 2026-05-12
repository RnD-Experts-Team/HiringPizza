<?php

namespace App\Console\Commands;

use App\Services\EmployeeCsvImportService;
use Illuminate\Console\Command;

class ImportEmployeesCsvCommand extends Command
{
    protected $signature = 'employees:import-csv
        {path : Absolute or relative path to the CSV file}
        {--dry-run : Validate and map rows without writing to the database}
        {--mode=update : update|create-only|skip-existing}
        {--no-create-missing-stores : Do not create stores that are missing}
        {--no-create-missing-positions : Do not create missing positions}
        {--no-create-missing-id-types : Do not create missing id types}
        {--altametrics-id-type=Altametrics ID : Label used for Altametrics ID id_type}
        {--paychecks-id-type=Paychecks ID : Label used for Paychecks ID id_type}
        {--clock-code-id-type=Altametrics Clock Code : Label used for Altametrics Clock code id_type}
        {--default-employment-type= : Fallback employment type when missing (W2|1099)}
        {--default-gender= : Fallback gender when missing (male|female)}';

    protected $description = 'Import employee aggregate data from a CSV file with robust normalization and row-level error handling.';

    public function handle(EmployeeCsvImportService $service): int
    {
        $mode = strtolower((string) $this->option('mode'));
        if (!in_array($mode, ['update', 'create-only', 'skip-existing'], true)) {
            $this->error("Invalid --mode '{$mode}'. Use one of: update, create-only, skip-existing.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $resolvedPath = $this->resolvePath($path);

        $this->info('Starting CSV employee import...');
        $this->line('File: ' . $resolvedPath);
        $this->line('Mode: ' . $mode . ($this->option('dry-run') ? ' (dry-run)' : ''));

        $result = $service->import($resolvedPath, [
            'dry_run' => (bool) $this->option('dry-run'),
            'mode' => $mode,
            'create_missing_stores' => !$this->option('no-create-missing-stores'),
            'create_missing_positions' => !$this->option('no-create-missing-positions'),
            'create_missing_id_types' => !$this->option('no-create-missing-id-types'),
            'altametrics_id_type' => (string) $this->option('altametrics-id-type'),
            'paychecks_id_type' => (string) $this->option('paychecks-id-type'),
            'clock_code_id_type' => (string) $this->option('clock-code-id-type'),
            'default_employment_type' => $this->option('default-employment-type'),
            'default_gender' => $this->option('default-gender'),
        ]);

        $summary = $result['summary'];
        $this->newLine();
        $this->info('Import summary:');
        $this->line('Rows:      ' . $summary['rows_total']);
        $this->line('Created:   ' . $summary['created']);
        $this->line('Updated:   ' . $summary['updated']);
        $this->line('Skipped:   ' . $summary['skipped']);
        $this->line('Failed:    ' . $summary['failed']);

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings (first 20):');
            foreach (array_slice($result['warnings'], 0, 20) as $warning) {
                $this->line('Line ' . $warning['line'] . ': ' . $warning['warning'] . (isset($warning['value']) ? ' [' . $warning['value'] . ']' : ''));
            }

            if (count($result['warnings']) > 20) {
                $this->line('... and ' . (count($result['warnings']) - 20) . ' more warnings.');
            }
        }

        if ($result['failures'] !== []) {
            $this->newLine();
            $this->error('Failures (first 20):');
            foreach (array_slice($result['failures'], 0, 20) as $failure) {
                $name = trim(($failure['row']['First_name'] ?? '') . ' ' . ($failure['row']['Last_name'] ?? ''));
                $this->line('Line ' . $failure['line'] . ': ' . $failure['error'] . ' [' . $name . ']');
            }

            if (count($result['failures']) > 20) {
                $this->line('... and ' . (count($result['failures']) - 20) . ' more failures.');
            }

            return self::FAILURE;
        }

        $this->info('Import completed successfully.');

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
