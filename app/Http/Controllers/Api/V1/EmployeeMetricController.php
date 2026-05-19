<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EmployeeMetricImportRequest;
use App\Http\Requests\Api\V1\EmployeeMetricIndexRequest;
use App\Services\EmployeeMetricImportService;
use App\Services\EmployeeMetricQueryService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class EmployeeMetricController extends Controller
{
    public function __construct(
        private readonly EmployeeMetricImportService $importService,
        private readonly EmployeeMetricQueryService $queryService
    ) {
    }

    public function import(EmployeeMetricImportRequest $request): JsonResponse
    {
        try {
            $result = $this->importService->import($request->file('file'), $request->validated());
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    public function index(EmployeeMetricIndexRequest $request): JsonResponse
    {
        $metrics = $this->queryService->index($request->validated());

        return response()->json($metrics);
    }
}
