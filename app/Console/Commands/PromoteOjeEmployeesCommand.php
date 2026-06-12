<?php

namespace App\Console\Commands;

use App\Services\OjePromotionService;
use Illuminate\Console\Command;

class PromoteOjeEmployeesCommand extends Command
{
    protected $signature = 'employees:promote-oje
        {--dry-run : List eligible employees without writing any changes}';

    protected $description = 'Promote employees from OJE to Hired once their 30-day evaluation period is complete.';

    public function handle(OjePromotionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be written.');
        }

        $this->info('Checking for OJE employees due for promotion...');

        $result = $service->promoteDueEmployees($dryRun);

        $this->line('Eligible: ' . $result['total']);
        $this->line('Promoted: ' . ($dryRun ? '0 (dry-run)' : $result['promoted']));

        if ($result['failed'] > 0) {
            $this->newLine();
            $this->error('Failures (' . $result['failed'] . '):');
            foreach ($result['failures'] as $failure) {
                $this->line('  Employee #' . $failure['employee_id'] . ': ' . $failure['error']);
            }

            return self::FAILURE;
        }

        $this->info('OJE promotion completed successfully.');

        return self::SUCCESS;
    }
}
