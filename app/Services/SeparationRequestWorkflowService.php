<?php

namespace App\Services;

use App\Enums\SeparationType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\SeparationRequestAttachment;
use App\Models\SeparationRequest;
use App\Models\SeparationRequestDecision;
use App\Models\Store;
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


            return $this->load($separationRequest);
        });
    }

    public function makeDecision(SeparationRequest $separationRequest, array $data): SeparationRequestDecision
    {
        return DB::transaction(function () use ($separationRequest, $data) {
            $decision = SeparationRequestDecision::query()->create([
                'separation_request_id' => $separationRequest->id,
                'user_id' => Auth::id(),
                'decision' => $data['decision'],
                'notes' => $data['notes'] ?? null,
                'completed_at' => $data['decision'] === 'completed' ? now() : null,
            ]);

            // If decision is completed, update employee status
            if ($data['decision'] === 'completed') {
                $newStatus = $separationRequest->separation_type === SeparationType::Termination
                    ? EmployeeStatus::Terminated
                    : EmployeeStatus::Resigned;

                // Add status history entry
                $separationRequest->employee->statusHistories()->create([
                    'status' => $newStatus,
                    'effective_date' => $separationRequest->final_working_day,
                    'store_id' => $separationRequest->store_id,
                ]);
            }


            return $decision;
        });
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
        $hasStoreAssignment = $employee->stores()
            ->where('store_id', $store->id)
            ->exists();

        if (!$hasStoreAssignment) {
            throw new ModelNotFoundException(
                "Employee {$employee->id} is not assigned to store {$store->store_number}"
            );
        }
    }

}
