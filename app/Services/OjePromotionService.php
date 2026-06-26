<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Store;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
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
                        'status' => EmployeeStatus::Hired,
                        'effective_date' => $ojeRecord->effective_date->copy()->addMonth()->toDateString(),
                        'store_id' => $ojeRecord->store_id,
                        'notes' => 'Auto-promoted from OJE after completing the 30-day evaluation period.',
                    ]);

                    $this->sendPromotionNotification($ojeRecord);
                });

                $promoted++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'employee_id' => $ojeRecord->employee_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'total' => $dueRecords->count(),
            'promoted' => $promoted,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }

    private function sendPromotionNotification(EmployeeStatusHistory $ojeRecord): void
    {
        $employee = Employee::find($ojeRecord->employee_id);
        $store = Store::find($ojeRecord->store_id);

        if (!$employee || !$store) {
            return;
        }

        $employeeName = trim("{$employee->first_name} {$employee->last_name}");

        $this->recordEvent('notifications.v1.notification.role.send', [
            'channels' => ['web'],
            'roles' => ['Store Manager', 'Hiring Specialist', 'Hiring Manager'],
            'stores' => [$store->store_number],
            'payload' => [
                'type' => 'employee_promoted_from_oje',
                'title' => 'Employee promoted from OJE',
                'body' => "{$employeeName} has been promoted to Hired at Store {$store->store_number}.",
                'action_url' => "/hiring/store/{$store->store_number}/employee/{$employee->id}",
            ],
        ]);
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory = app(HiringEventFactory::class);
        $outbox = app(HiringOutboxService::class);

        $envelope = $factory->make($subject, $data);
        $row = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }
}
