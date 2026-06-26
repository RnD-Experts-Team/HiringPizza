<?php

namespace App\Services;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\HiringRequest;
use App\Models\HiringRequestCandidate;
use App\Models\HiringRequestDecision;
use App\Models\HiringRequestPosition;
use App\Models\Store;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HiringRequestWorkflowService
{
    public function resolveStoreByNumber(string $storeNumber): Store
    {
        return Store::query()->where('store_number', $storeNumber)->firstOrFail();
    }

    public function create(Store $store, array $data): HiringRequest
    {
        return DB::transaction(function () use ($store, $data) {
            $hiringRequest = HiringRequest::query()->create([
                'store_id' => $store->id,
                'user_id' => Auth::id(),
                'employees_needed' => $data['employees_needed'],
                'desired_start_date' => $data['desired_start_date'],
                'final_notes' => $data['final_notes'] ?? null,
            ]);

            // Add candidates if provided
            if (!empty($data['candidates'])) {
                foreach ($data['candidates'] as $candidate) {
                    HiringRequestCandidate::query()->create([
                        'hiring_request_id' => $hiringRequest->id,
                        'name' => $candidate['name'],
                        'phone' => $candidate['phone'],
                        'email' => $candidate['email'],
                    ]);
                }
            }

            // Add position details
            foreach ($data['positions'] as $position) {
                HiringRequestPosition::query()->create([
                    'hiring_request_id' => $hiringRequest->id,
                    'shift_type' => $position['shift_type'],
                    'availability_type' => $position['availability_type'],
                    'notes' => $position['notes'],
                ]);
            }


            $this->sendCreatedNotification($store, $hiringRequest);

            return $this->load($hiringRequest);
        });
    }

    public function makeDecision(HiringRequest $hiringRequest, array $data): HiringRequestDecision
    {
        return DB::transaction(function () use ($hiringRequest, $data) {
            // Verify all employee IDs exist and are distinct
            $employees = Employee::query()
                ->whereIn('id', $data['employee_ids'])
                ->get();

            if ($employees->count() !== count($data['employee_ids'])) {
                throw new \InvalidArgumentException('One or more employee IDs do not exist');
            }

            $decision = HiringRequestDecision::query()->create([
                'hiring_request_id' => $hiringRequest->id,
                'user_id' => Auth::id(),
                'number_hired' => $data['number_hired'],
                'completed_at' => now(),
            ]);

            // Link hired employees to this decision
            foreach ($employees as $employee) {
                $decision->employees()->create([
                    'employee_id' => $employee->id,
                ]);
            }


            $this->sendDecisionNotification($hiringRequest);

            return $decision->load('employees.employee');
        });
    }

    private function sendCreatedNotification(Store $store, HiringRequest $request): void
    {
        $this->recordEvent('notifications.v1.notification.role.send', [
            'channels' => ['web'],
            'roles'    => ['Hiring Specialist', 'Hiring Manager'],
            'stores'   => [$store->store_number],
            'payload'  => [
                'type'       => 'hiring_request_created',
                'title'      => 'Hiring request submitted',
                'body'       => "A hiring request for {$request->employees_needed} employee(s) has been submitted for Store {$store->store_number}.",
                'action_url' => "/hiring/store/{$store->store_number}/hiring-requests/{$request->id}",
            ],
        ]);
    }

    private function sendDecisionNotification(HiringRequest $request): void
    {
        $storeNumber = $request->store()->value('store_number');

        $this->recordEvent('notifications.v1.notification.role.send', [
            'channels' => ['web'],
            'roles'    => ['Store Manager'],
            'stores'   => [$storeNumber],
            'payload'  => [
                'type'       => 'hiring_request_decided',
                'title'      => 'Hiring request decision made',
                'body'       => "A decision has been made on hiring request #{$request->id} for Store {$storeNumber}.",
                'action_url' => "/hiring/store/{$storeNumber}/hiring-requests/{$request->id}",
            ],
        ]);
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory = app(HiringEventFactory::class);
        $outbox  = app(HiringOutboxService::class);

        $envelope = $factory->make($subject, $data);
        $row      = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

    public function load(HiringRequest $hiringRequest): HiringRequest
    {
        return $hiringRequest->load([
            'store',
            'user',
            'candidates',
            'positions',
            'decisions.user',
            'decisions.employees.employee',
        ]);
    }

}
