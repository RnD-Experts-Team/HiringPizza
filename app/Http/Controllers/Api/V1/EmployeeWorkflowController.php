<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EmployeeDestroyRequest;
use App\Http\Requests\Api\V1\EmployeeGlobalIndexRequest;
use App\Http\Requests\Api\V1\EmployeeIndexRequest;
use App\Http\Requests\Api\V1\EmployeeOperationalRequest;
use App\Http\Requests\Api\V1\EmployeeSeparationStatusExportRequest;
use App\Http\Requests\Api\V1\EmployeeStatusChangeRequest;
use App\Http\Requests\Api\V1\EmployeeTenureExportRequest;
use App\Http\Requests\Api\V1\EmployeeWorkflowStoreRequest;
use App\Http\Requests\Api\V1\EmployeeWorkflowUpdateRequest;
use App\Models\Employee;
use App\Services\EmployeeExportService;
use App\Services\EmployeeMetricQueryService;
use App\Services\EmployeeQueryService;
use App\Services\EmployeeWorkflowService;
use App\Services\ExportHiringBernardTempService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;

class EmployeeWorkflowController extends Controller
{
    public function __construct(
        private readonly EmployeeWorkflowService $workflowService,
        private readonly EmployeeQueryService $queryService,
        private readonly EmployeeMetricQueryService $metricQueryService,
        private readonly EmployeeExportService $exportService,
        private readonly ExportHiringBernardTempService $exportHiringBernardTempService
    ) {
    }

    public function indexGlobal(EmployeeGlobalIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $storeIds = collect($filters['storeIds'] ?? [])
            ->map(fn(string $num) => $this->workflowService->resolveStoreByNumber($num)->id)
            ->all();

        $employees = $this->queryService->indexGlobal($storeIds, $filters);
        $canViewSensitive = $this->hasHiringSpecialistPermission($request);

        if ($employees instanceof LengthAwarePaginator) {
            $employees->setCollection(
                $employees->getCollection()->map(fn(Employee $employee) => $this->exposeSensitiveAttributes($employee, $canViewSensitive))
            );
        }

        return response()->json($employees);
    }

    public function index(EmployeeIndexRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employees = $this->queryService->index($store, $request->validated());
        $canViewSensitive = $this->hasHiringSpecialistPermission($request);

        if ($employees instanceof LengthAwarePaginator) {
            $employees->setCollection(
                $employees->getCollection()->map(fn(Employee $employee) => $this->exposeSensitiveAttributes($employee, $canViewSensitive))
            );
        }

        return response()->json($employees);
    }

    public function show(Request $request, string $storeNumber, Employee $employee): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employee = $this->workflowService->loadForStore($store, $employee);
        $employee = $this->exposeSensitiveAttributes($employee, $this->hasHiringSpecialistPermission($request));

        return response()->json(['data' => $employee]);
    }

    public function operational(EmployeeOperationalRequest $request, string $storeNumber, Employee $employee): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $employee = $this->workflowService->loadForStore($store, $employee);
        $employee = $this->exposeSensitiveAttributes($employee, $this->hasHiringSpecialistPermission($request));

        $operational = $this->metricQueryService->operationalForEmployee(
            $employee->id,
            $request->validated()
        );

        return response()->json([
            'data' => [
                'employee' => $employee,
                'operational' => $operational,
            ],
        ]);
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

    public function export(): StreamedResponse
    {
        return $this->exportService->export();
    }

    public function exportHiringTempBernard(Request $request): StreamedResponse
    {
        $store = null;

        if ($request->filled('store_number')) {
            $store = $this->workflowService->resolveStoreByNumber(
                $request->query('store_number')
            );
        }

        return $this->exportHiringBernardTempService->export($store);
    }

    public function exportTenure(EmployeeTenureExportRequest $request): StreamedResponse
    {
        $filters = $request->validated();

        return $this->exportService->exportTenure($filters['from'] ?? null, $filters['to'] ?? null);
    }

    public function exportSeparationStatusHistory(EmployeeSeparationStatusExportRequest $request): StreamedResponse
    {
        $filters = $request->validated();

        return $this->exportService->exportSeparationStatusHistory($filters['effective_from'] ?? null);
    }

    private function exposeSensitiveAttributes(Employee $employee, bool $canViewSensitive = true): Employee
    {
        if (!$canViewSensitive) {
            $employee->makeHidden(['attachments']);

            return $employee;
        }

        $employee->makeVisible(['ssn']);

        if ($employee->relationLoaded('financialInfos')) {
            $employee->financialInfos->makeVisible(['account_number', 'routing_number']);
        }

        return $employee;
    }

    /**
     * "In any way" — checked against both roles and permissions returned by
     * AuthTokenStoreScopeMiddleware, matched as a loose substring so any
     * variant (e.g. "hiring_specialist", "Hiring Specialist (Store)") counts.
     */
    private function hasHiringSpecialistPermission(Request $request): bool
    {
        $roles = (array) $request->attributes->get('authz_roles', []);
        $permissions = (array) $request->attributes->get('authz_permissions', []);

        foreach (array_merge($roles, $permissions) as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $entry) ?? '');

            if (str_contains($normalized, 'hiring specialist')) {
                return true;
            }
        }

        return false;
    }

}
