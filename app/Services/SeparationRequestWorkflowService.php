<?php

namespace App\Services;

use App\Enums\SeparationType;
use App\Enums\EmployeeStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\SeparationRequestAttachment;
use App\Models\SeparationRequest;
use App\Models\SeparationRequestDecision;
use App\Models\Store;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use App\Services\HiringEvents\ModelChangeSet;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SeparationRequestWorkflowService
{
    public function resolveStoreByNumber(string $storeNumber): Store
    {
        return Store::query()->where('store_number', $storeNumber)->firstOrFail();
    }

    public function create(Store $store, array $data): SeparationRequest
    {
        return DB::transaction(function () use ($store, $data) {
            $employee = Employee::findOrFail($data['employee_id']);

            // Verify employee is assigned to this store
            $this->assertEmployeeInStore($store, $employee);

            $separationRequest = SeparationRequest::query()->create([
                'store_id' => $store->id,
                'user_id' => Auth::id(),
                'employee_id' => $employee->id,
                'separation_type' => $data['separation_type'],
                'final_working_day' => $data['final_working_day'],
                'termination_letter' => $data['termination_letter'] ?? null,
                'termination_reason' => $data['termination_reason'] ?? null,
                'termination_reason_details' => $data['termination_reason_details'] ?? null,
                'resignation_reason' => $data['resignation_reason'] ?? null,
                'resignation_reason_details' => $data['resignation_reason_details'] ?? null,
                'additional_notes' => $data['additional_notes'] ?? null,
            ]);

            $this->storeAttachments($separationRequest, $data['attachments'] ?? []);

            $loaded = $this->load($separationRequest);
            $this->sendCreatedNotification($store, $separationRequest, $employee);

            return $loaded;
        });
    }

    public function makeDecision(SeparationRequest $separationRequest, array $data, ?Request $request = null): SeparationRequestDecision
    {
        return DB::transaction(function () use ($separationRequest, $data, $request) {
            $decision = SeparationRequestDecision::query()->create([
                'separation_request_id' => $separationRequest->id,
                'user_id' => Auth::id(),
                'decision' => $data['decision'],
                'notes' => $data['notes'] ?? null,
                'completed_at' => $data['decision'] === 'completed' ? now() : null,
            ]);

            $this->sendDecisionNotification($separationRequest);

            // If decision is completed, update employee status
            if ($data['decision'] === 'completed') {
                $employee = Employee::query()->findOrFail($separationRequest->employee_id);
                $beforeSnapshot = $this->snapshotEmployee($this->loadEmployee($employee->fresh()));

                $newStatus = $separationRequest->separation_type === SeparationType::Termination
                    ? EmployeeStatus::Terminated
                    : EmployeeStatus::Resigned;

                // Add status history entry
                $employee->statusHistories()->create([
                    'status' => $newStatus,
                    'effective_date' => $separationRequest->final_working_day,
                    'store_id' => $separationRequest->store_id,
                ]);

                $loadedEmployee = $this->loadEmployee($employee->fresh());
                $afterSnapshot = $this->snapshotEmployee($loadedEmployee);

                $changedFields = ModelChangeSet::fromArrays(
                    $beforeSnapshot,
                    $afterSnapshot,
                    $this->snapshotChangeKeys($beforeSnapshot, $afterSnapshot)
                );

                if ($changedFields !== []) {
                    $storeNumber = $separationRequest->store()->value('store_number');

                    $this->recordEvent('hiring.v1.employee.updated', [
                        'employee_id' => $employee->id,
                        'store_number' => $storeNumber,
                        'changed_fields' => $changedFields,
                    ], $request);
                }
            }


            return $decision;
        });
    }

    private function sendCreatedNotification(Store $store, SeparationRequest $request, Employee $employee): void
    {
        $employeeName = trim("{$employee->first_name} {$employee->last_name}");

        $this->recordEvent('notifications.v1.notification.role.send', [
            'channels' => ['web'],
            'roles'    => ['Hiring Specialist', 'Hiring Manager'],
            'stores'   => [$store->store_number],
            'payload'  => [
                'type'       => 'separation_request_created',
                'title'      => 'Separation request submitted',
                'body'       => "A separation request for {$employeeName} has been submitted for Store {$store->store_number}.",
                'action_url' => "/hiring/store/{$store->store_number}/separation-requests/{$request->id}",
            ],
        ]);
    }

    private function sendDecisionNotification(SeparationRequest $request): void
    {
        $storeNumber = $request->store()->value('store_number');

        $this->recordEvent('notifications.v1.notification.role.send', [
            'channels' => ['web'],
            'roles'    => ['Store Manager'],
            'stores'   => [$storeNumber],
            'payload'  => [
                'type'       => 'separation_request_decided',
                'title'      => 'Separation request decision made',
                'body'       => "A decision has been made on separation request #{$request->id} for Store {$storeNumber}.",
                'action_url' => "/hiring/store/{$storeNumber}/separation-requests/{$request->id}",
            ],
        ]);
    }

    public function load(SeparationRequest $separationRequest): SeparationRequest
    {
        return $separationRequest->load([
            'store',
            'user',
            'employee',
            'attachments',
            'decisions.user',
        ]);
    }

    protected function storeAttachments(SeparationRequest $separationRequest, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $path = $attachment->store('separation-requests/' . $separationRequest->id, 'public');

            SeparationRequestAttachment::query()->create([
                'separation_request_id' => $separationRequest->id,
                'file_path' => $path,
                'original_name' => $attachment->getClientOriginalName(),
                'mime_type' => $attachment->getClientMimeType(),
                'file_size' => $attachment->getSize(),
            ]);
        }
    }

    protected function assertEmployeeInStore(Store $store, Employee $employee): void
    {
        $latestStoreId = $employee->stores()
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('store_id');

        if ($latestStoreId === null || (int) $latestStoreId !== $store->id) {
            throw new ModelNotFoundException(
                "Employee {$employee->id} is not assigned to store {$store->store_number}"
            );
        }
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

    private function recordEvent(string $subject, array $data, ?Request $request = null): void
    {
        $factory = app(HiringEventFactory::class);
        $outbox = app(HiringOutboxService::class);

        $envelope = $factory->make($subject, $data, $request);
        $row = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

}
