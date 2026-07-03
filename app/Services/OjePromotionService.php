<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Store;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use App\Services\HiringEvents\ModelChangeSet;
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
                    $employee = Employee::find($ojeRecord->employee_id);
                    $store = Store::find($ojeRecord->store_id);

                    $beforeSnapshot = $employee
                        ? $this->snapshotEmployee($this->loadEmployee($employee))
                        : null;

                    EmployeeStatusHistory::query()->create([
                        'employee_id' => $ojeRecord->employee_id,
                        'status' => EmployeeStatus::Hired,
                        'effective_date' => $ojeRecord->effective_date->copy()->addMonth()->toDateString(),
                        'store_id' => $ojeRecord->store_id,
                        'notes' => 'Auto-promoted from OJE after completing the 30-day evaluation period.',
                    ]);

                    $this->sendPromotionNotification($employee, $store);

                    if ($employee && $store) {
                        $afterSnapshot = $this->snapshotEmployee($this->loadEmployee($employee->fresh()));

                        $changedFields = ModelChangeSet::fromArrays(
                            $beforeSnapshot,
                            $afterSnapshot,
                            $this->snapshotChangeKeys($beforeSnapshot, $afterSnapshot)
                        );

                        if ($changedFields !== []) {
                            $this->recordEvent('hiring.v1.employee.updated', [
                                'employee_id' => $employee->id,
                                'store_number' => $store->store_number,
                                'changed_fields' => $changedFields,
                            ]);
                        }
                    }
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

    private function sendPromotionNotification(?Employee $employee, ?Store $store): void
    {
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

    private function loadEmployee(Employee $employee): Employee
    {
        return $employee->load([
            'statusHistories.store',
            'payHistories',
            'contacts',
            'addresses',
            'availabilityDays.times',
            'financialInfos',
            'ids.idType',
            'obsession',
            'positions.position',
            'stores.store',
            'maritals.maritalStatus',
            'attachments.attachmentType',
        ]);
    }

    private function snapshotEmployee(Employee $employee): array
    {
        return $employee->toArray();
    }

    private function snapshotChangeKeys(array $before, array $after): array
    {
        return array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
    }
}
