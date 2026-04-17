<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EmployeeDestroyRequest;
use App\Http\Requests\Api\V1\EmployeeIndexRequest;
use App\Http\Requests\Api\V1\EmployeeStatusChangeRequest;
use App\Http\Requests\Api\V1\EmployeeWorkflowStoreRequest;
use App\Http\Requests\Api\V1\EmployeeWorkflowUpdateRequest;
use App\Models\Employee;
use App\Services\EmployeeQueryService;
use App\Services\EmployeeWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeWorkflowController extends Controller
{
    public function __construct(
        private readonly EmployeeWorkflowService $workflowService,
        private readonly EmployeeQueryService $queryService
    ) {
    }

    public function index(EmployeeIndexRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employees = $this->queryService->index($store, $request->validated());

        if ($employees instanceof LengthAwarePaginator) {
            $employees->setCollection(
                $employees->getCollection()->map(fn(Employee $employee) => $this->exposeSensitiveAttributes($employee))
            );
        }

        return response()->json($employees);
    }

    public function show(string $storeNumber, Employee $employee): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employee = $this->workflowService->loadForStore($store, $employee);
        $employee = $this->exposeSensitiveAttributes($employee);

        return response()->json(['data' => $employee]);
    }

    public function store(EmployeeWorkflowStoreRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employee = $this->workflowService->create($store, $request->validated(), $request);
        $employee = $this->exposeSensitiveAttributes($employee);

        return response()->json(['data' => $employee], 201);
    }

    public function update(EmployeeWorkflowUpdateRequest $request, string $storeNumber, Employee $employee): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employee = $this->workflowService->update($store, $employee, $request->validated(), $request);
        $employee = $this->exposeSensitiveAttributes($employee);

        return response()->json(['data' => $employee]);
    }

    public function changeStatus(EmployeeStatusChangeRequest $request, string $storeNumber, Employee $employee): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employee = $this->workflowService->changeStatus($store, $employee, $request->validated());
        $employee = $this->exposeSensitiveAttributes($employee);

        return response()->json(['data' => $employee]);
    }

    private function exposeSensitiveAttributes(Employee $employee): Employee
    {
        $employee->makeVisible(['ssn']);

        if ($employee->relationLoaded('financialInfos')) {
            $employee->financialInfos->makeVisible(['account_number', 'routing_number']);
        }

        return $employee;
    }

}
