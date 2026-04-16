<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReferenceCatalogSyncRequest;
use App\Services\ReferenceDataService;
use Illuminate\Http\JsonResponse;

class ReferenceCatalogController extends Controller
{
    public function __construct(private readonly ReferenceDataService $referenceDataService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->referenceDataService->index(),
        ]);
    }

    public function sync(ReferenceCatalogSyncRequest $request): JsonResponse
    {
        $catalog = $this->referenceDataService->sync($request->validated());

        return response()->json([
            'data' => $catalog,
            'message' => 'Reference catalog synchronized.',
        ]);
    }
}
