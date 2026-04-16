<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\EmployeeAttachment;
use App\Models\EmployeeAvailabilityDay;
use App\Models\EmployeeAvailabilityTime;
use App\Models\EmployeeContact;
use App\Models\EmployeeFinancialInfo;
use App\Models\EmployeeId;
use App\Models\EmployeeMarital;
use App\Models\EmployeeObsession;
use App\Models\EmployeePayHistory;
use App\Models\EmployeePosition;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStore;
use App\Models\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
class EmployeeWorkflowService
{
    public function resolveStoreByNumber(string $storeNumber): Store
    {
        return Store::query()->where('store_number', $storeNumber)->firstOrFail();
    }

    public function create(Store $store, array $data): Employee
    {
        return DB::transaction(function () use ($store, $data) {
            $employee = Employee::query()->create(Arr::only($data, [
                'first_name',
                'middle_name',
                'last_name',
                'gender',
                'ssn',
                'employment_type',
            ]));

            $this->syncStoreAssignments($employee, $store, $data['store_assignments'] ?? []);
            $this->syncStatusHistory($employee, $store, $data['status_history'] ?? []);
            $this->syncPayHistory($employee, $data['pay_history'] ?? []);
            $this->syncContacts($employee, $data['contacts'] ?? []);
            $this->syncAddresses($employee, $data['addresses'] ?? []);
            $this->syncAvailability($employee, $data['availability'] ?? []);
            $this->syncFinancialInfo($employee, $data['financial_info'] ?? []);
            $this->syncEmployeeIds($employee, $data['employee_ids'] ?? []);
            $this->syncObsession($employee, $data['obsession'] ?? null);
            $this->syncPositions($employee, $data['positions'] ?? []);
            $this->syncMarital($employee, $data['marital_history'] ?? []);
            $this->syncAttachments($employee, $data['attachments'] ?? []);

            $data['actor_user_id'] = Auth::id();
            $this->createAuditLog($employee, $data['actor_user_id'] ?? null, 'create', [
                'store_number' => $store->store_number,
                'workflow' => 'employee.create',
            ]);

            return $this->loadEmployee($employee);
        });
    }

    public function update(Store $store, Employee $employee, array $data): Employee
    {
        $this->assertEmployeeInStore($store, $employee);

        return DB::transaction(function () use ($store, $employee, $data) {
            $employee->fill(Arr::only($data, [
                'first_name',
                'middle_name',
                'last_name',
                'gender',
                'ssn',
                'employment_type',
            ]));
            $employee->save();

            if (array_key_exists('store_assignments', $data)) {
                $this->syncStoreAssignments($employee, $store, $data['store_assignments'] ?? []);
            }

            if (array_key_exists('status_history', $data)) {
                $this->syncStatusHistory($employee, $store, $data['status_history'] ?? []);
            }

            if (array_key_exists('pay_history', $data)) {
                $this->syncPayHistory($employee, $data['pay_history'] ?? []);
            }

            if (array_key_exists('contacts', $data)) {
                $this->syncContacts($employee, $data['contacts'] ?? []);
            }

            if (array_key_exists('addresses', $data)) {
                $this->syncAddresses($employee, $data['addresses'] ?? []);
            }

            if (array_key_exists('availability', $data)) {
                $this->syncAvailability($employee, $data['availability'] ?? []);
            }

            if (array_key_exists('financial_info', $data)) {
                $this->syncFinancialInfo($employee, $data['financial_info'] ?? []);
            }

            if (array_key_exists('employee_ids', $data)) {
                $this->syncEmployeeIds($employee, $data['employee_ids'] ?? []);
            }

            if (array_key_exists('obsession', $data)) {
                $this->syncObsession($employee, $data['obsession'] ?? null);
            }

            if (array_key_exists('positions', $data)) {
                $this->syncPositions($employee, $data['positions'] ?? []);
            }

            if (array_key_exists('marital_history', $data)) {
                $this->syncMarital($employee, $data['marital_history'] ?? []);
            }

            if (array_key_exists('attachments', $data)) {
                $this->syncAttachments($employee, $data['attachments'] ?? []);
            }
            $data['actor_user_id'] = Auth::id();
            $this->createAuditLog($employee, $data['actor_user_id'] ?? null, 'update', [
                'store_number' => $store->store_number,
                'workflow' => 'employee.update',
            ]);

            return $this->loadEmployee($employee->fresh());
        });
    }

    public function changeStatus(Store $store, Employee $employee, array $data): Employee
    {
        $this->assertEmployeeInStore($store, $employee);

        return DB::transaction(function () use ($store, $employee, $data) {
            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'status' => $data['status'],
                'effective_date' => $data['effective_date'] ?? now()->toDateString(),
                'store_id' => $store->id,
                'notes' => $data['notes'] ?? null,
            ]);
            $data['actor_user_id'] = Auth::id();
            $this->createAuditLog($employee, $data['actor_user_id'] ?? null, 'status_change', [
                'store_number' => $store->store_number,
                'status' => $data['status'],
                'effective_date' => $data['effective_date'] ?? null,
            ]);

            return $this->loadEmployee($employee->fresh());
        });
    }

    public function loadForStore(Store $store, Employee $employee): Employee
    {
        $this->assertEmployeeInStore($store, $employee);

        return $this->loadEmployee($employee);
    }

    public function assertEmployeeInStore(Store $store, Employee $employee): void
    {
        $inStore = EmployeeStore::query()
            ->where('employee_id', $employee->id)
            ->where('store_id', $store->id)
            ->exists();

        if (!$inStore) {
            throw (new ModelNotFoundException())->setModel(Employee::class, [$employee->id]);
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

    private function syncStoreAssignments(Employee $employee, Store $currentStore, array $rows): void
    {
        if ($rows === []) {
            $rows = [
                [
                    'store_id' => $currentStore->id,
                    'effective_date' => now()->toDateString(),
                ]
            ];
        }

        EmployeeStore::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeStore::query()->create([
                'employee_id' => $employee->id,
                'store_id' => $row['store_id'] ?? $currentStore->id,
                'effective_date' => $row['effective_date'] ?? now()->toDateString(),
            ]);
        }
    }

    private function syncStatusHistory(Employee $employee, Store $store, array $rows): void
    {
        if ($rows === []) {
            $rows = [
                [
                    'status' => 'hired',
                    'effective_date' => now()->toDateString(),
                    'store_id' => $store->id,
                ]
            ];
        }

        EmployeeStatusHistory::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'status' => $row['status'],
                'effective_date' => $row['effective_date'] ?? now()->toDateString(),
                'store_id' => $row['store_id'] ?? $store->id,
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }

    private function syncPayHistory(Employee $employee, array $rows): void
    {
        EmployeePayHistory::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeePayHistory::query()->create([
                'employee_id' => $employee->id,
                'base_pay' => $row['base_pay'],
                'performance_pay' => $row['performance_pay'],
                'effective_date' => $row['effective_date'],
            ]);
        }
    }

    private function syncContacts(Employee $employee, array $rows): void
    {
        EmployeeContact::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeContact::query()->create([
                'employee_id' => $employee->id,
                'contact_name' => $row['contact_name'],
                'contact_type' => $row['contact_type'],
                'contact_value' => $row['contact_value'],
                'is_primary' => $row['is_primary'] ?? false,
            ]);
        }
    }

    private function syncAddresses(Employee $employee, array $rows): void
    {
        EmployeeAddress::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeAddress::query()->create([
                'employee_id' => $employee->id,
                'address_name' => $row['address_name'],
                'address_1' => $row['address_1'],
                'address_2' => $row['address_2'] ?? null,
                'city' => $row['city'],
                'state' => $row['state'],
                'zip_code' => $row['zip_code'],
                'country' => $row['country'] ?? 'US',
                'is_primary' => $row['is_primary'] ?? false,
            ]);
        }
    }

    private function syncAvailability(Employee $employee, array $rows): void
    {
        $dayIds = EmployeeAvailabilityDay::query()
            ->where('employee_id', $employee->id)
            ->pluck('id');

        if ($dayIds->isNotEmpty()) {
            EmployeeAvailabilityTime::query()->whereIn('availability_day_id', $dayIds)->delete();
        }

        EmployeeAvailabilityDay::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            $day = EmployeeAvailabilityDay::query()->create([
                'employee_id' => $employee->id,
                'day_of_week' => $row['day_of_week'],
                'shift_type' => $row['shift_type'],
            ]);

            foreach (($row['times'] ?? []) as $time) {
                EmployeeAvailabilityTime::query()->create([
                    'availability_day_id' => $day->id,
                    'available_from' => $time['available_from'],
                    'available_to' => $time['available_to'],
                ]);
            }
        }
    }

    private function syncFinancialInfo(Employee $employee, array $rows): void
    {
        EmployeeFinancialInfo::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeFinancialInfo::query()->create([
                'employee_id' => $employee->id,
                'account_number' => $row['account_number'],
                'routing_number' => $row['routing_number'],
                'account_type' => $row['account_type'],
                'effective_date' => $row['effective_date'],
            ]);
        }
    }

    private function syncEmployeeIds(Employee $employee, array $rows): void
    {
        EmployeeId::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeId::query()->create([
                'employee_id' => $employee->id,
                'id_type_id' => $row['id_type_id'],
                'id_value' => $row['id_value'],
            ]);
        }
    }

    private function syncObsession(Employee $employee, ?array $row): void
    {
        if ($row === null) {
            EmployeeObsession::query()->where('employee_id', $employee->id)->delete();

            return;
        }

        EmployeeObsession::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                't_shirt' => $row['t_shirt'] ?? null,
                'birth_date' => $row['birth_date'],
                'image_path' => $row['image_path'] ?? null,
                'religion' => $row['religion'] ?? null,
                'race' => $row['race'] ?? null,
                'notes' => $row['notes'] ?? null,
            ]
        );
    }

    private function syncPositions(Employee $employee, array $rows): void
    {
        EmployeePosition::query()->where('employee_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeePosition::query()->create([
                'employee_id' => $employee->id,
                'position_id' => $row['position_id'],
                'effective_date' => $row['effective_date'],
            ]);
        }
    }

    private function syncMarital(Employee $employee, array $rows): void
    {
        EmployeeMarital::query()->where('emp_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeMarital::query()->create([
                'emp_id' => $employee->id,
                'marital_id' => $row['marital_id'],
                'effective_date' => $row['effective_date'],
            ]);
        }
    }

    private function syncAttachments(Employee $employee, array $rows): void
    {
        EmployeeAttachment::query()->where('emp_id', $employee->id)->delete();

        foreach ($rows as $row) {
            EmployeeAttachment::query()->create([
                'emp_id' => $employee->id,
                'type_id' => $row['type_id'],
            ]);
        }
    }

    private function createAuditLog(Employee $employee, ?int $actorUserId, string $action, array $details): void
    {
        if ($actorUserId === null) {
            return;
        }

        AuditLog::query()->create([
            'user_id' => $actorUserId,
            'employee_id' => $employee->id,
            'action' => $action,
            'action_details' => $details,
        ]);
    }
}
