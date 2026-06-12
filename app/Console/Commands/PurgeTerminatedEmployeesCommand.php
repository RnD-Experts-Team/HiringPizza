<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeTerminatedEmployeesCommand extends Command
{
    protected $signature = 'employees:purge-terminated
        {--before=2026-01-01 : Delete employees whose last status change was before this date (YYYY-MM-DD)}
        {--dry-run : Preview which employees would be deleted without making any changes}';

    protected $description = 'Delete resigned/terminated employees whose last status change is before a given date.';

    public function handle(): int
    {
        $before = $this->option('before');
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Mode:   ' . ($dryRun ? 'dry-run (no changes will be made)' : 'live'));
        $this->line('Before: ' . $before);
        $this->newLine();

        $latestSubquery = EmployeeStatusHistory::select('employee_id', DB::raw('MAX(effective_date) as max_date'))
            ->groupBy('employee_id');

        $targetIds = EmployeeStatusHistory::joinSub($latestSubquery, 'latest', function ($join) {
                $join->on('employee_status_histories.employee_id', '=', 'latest.employee_id')
                     ->on('employee_status_histories.effective_date', '=', 'latest.max_date');
            })
            ->whereIn('employee_status_histories.status', ['resigned', 'terminated'])
            ->where('employee_status_histories.effective_date', '<', $before)
            ->pluck('employee_status_histories.employee_id')
            ->toArray();

        $this->info('Found ' . count($targetIds) . ' employees to delete.');

        if (empty($targetIds)) {
            $this->line('Nothing to delete.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry-run: no records were modified.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Proceed with deleting ' . count($targetIds) . ' employees and all their related records?')) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($targetIds) {
            DB::table('employee_stores')->whereIn('employee_id', $targetIds)->delete();
            DB::table('employee_obsessions')->whereIn('employee_id', $targetIds)->delete();
            DB::table('employee_positions')->whereIn('employee_id', $targetIds)->delete();
            DB::table('employee_maritals')->whereIn('emp_id', $targetIds)->delete();
            DB::table('employee_attachments')->whereIn('emp_id', $targetIds)->delete();

            $deleted = Employee::whereIn('id', $targetIds)->delete();
            $this->info("Deleted {$deleted} employees and all their related records.");
        });

        return self::SUCCESS;
    }
}
