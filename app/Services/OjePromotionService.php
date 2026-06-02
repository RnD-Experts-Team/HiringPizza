<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\EmployeeStatusHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OjePromotionService
{
    public function findDueOjeRecords(): Collection
    {
        $cutoff = now()->subMonth()->toDateString();

        return EmployeeStatusHistory::query()
            ->where('status', EmployeeStatus::OJE)
            ->whereDate('effective_date', '<=', $cutoff)
            ->whereRaw(
                'employee_status_histories.id = (
                    select latest.id
                    from employee_status_histories as latest
                    where latest.employee_id = employee_status_histories.employee_id
                    order by latest.effective_date desc, latest.id desc
                    limit 1
                )'
            )
            ->get();
    }

    public function promoteDueEmployees(bool $dryRun = false): array
    {
        $dueRecords = $this->findDueOjeRecords();

        $promoted = 0;
        $failed = 0;
        $failures = [];

        foreach ($dueRecords as $ojeRecord) {
            if ($dryRun) {
                $promoted++;
                continue;
            }

            try {
                DB::transaction(function () use ($ojeRecord): void {
                    EmployeeStatusHistory::query()->create([
                        'employee_id' => $ojeRecord->employee_id,
                        'status'      => EmployeeStatus::Hired,
                        'effective_date' => $ojeRecord->effective_date->copy()->addMonth()->toDateString(),
                        'store_id'    => $ojeRecord->store_id,
                        'notes'       => 'Auto-promoted from OJE after completing the 30-day evaluation period.',
                    ]);
                });

                $promoted++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'employee_id' => $ojeRecord->employee_id,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        return [
            'total'    => $dueRecords->count(),
            'promoted' => $promoted,
            'failed'   => $failed,
            'failures' => $failures,
        ];
    }
}
