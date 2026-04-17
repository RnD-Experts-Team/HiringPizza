<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HiringRequest;
use App\Models\HiringRequestCandidate;
use App\Models\HiringRequestDecision;
use App\Models\HiringRequestPosition;
use App\Models\Store;
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


            return $decision->load('employees.employee');
        });
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
