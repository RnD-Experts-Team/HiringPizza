<?php

use App\Http\Controllers\Api\V1\EmployeeWorkflowController;
use App\Http\Controllers\Api\V1\ReferenceCatalogController;
use App\Http\Controllers\Api\V1\SeparationRequestController;
use App\Http\Controllers\Api\V1\HiringRequestController;
use App\Http\Controllers\Api\V1\WorkflowRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('reference-catalog', [ReferenceCatalogController::class, 'index'])
        ->name('api.v1.reference-catalog.index');

    Route::put('reference-catalog', [ReferenceCatalogController::class, 'sync'])
        ->name('api.v1.reference-catalog.sync');

    Route::prefix('stores/{storeId}')
        ->where(['storeId' => '[A-Za-z0-9_-]+'])
        ->group(function (): void {
            Route::get('employees', [EmployeeWorkflowController::class, 'index'])
                ->name('api.v1.stores.employees.index');

            Route::post('employees', [EmployeeWorkflowController::class, 'store'])
                ->name('api.v1.stores.employees.store');

            Route::get('employees/{employee}', [EmployeeWorkflowController::class, 'show'])
                ->name('api.v1.stores.employees.show');

            Route::put('employees/{employee}', [EmployeeWorkflowController::class, 'update'])
                ->name('api.v1.stores.employees.update');

            Route::patch('employees/{employee}/status', [EmployeeWorkflowController::class, 'changeStatus'])
                ->name('api.v1.stores.employees.change-status');

            // Separation Request Workflow
            Route::get('requests', [WorkflowRequestController::class, 'index'])
                ->name('api.v1.stores.requests.index');

            Route::post('separation-requests', [SeparationRequestController::class, 'store'])
                ->name('api.v1.stores.separation-requests.store');

            Route::post('separation-requests/{separationRequest}/decision', [SeparationRequestController::class, 'decide'])
                ->name('api.v1.stores.separation-requests.decide');

            // Hiring Request Workflow
            Route::post('hiring-requests', [HiringRequestController::class, 'store'])
                ->name('api.v1.stores.hiring-requests.store');

            Route::post('hiring-requests/{hiringRequest}/decision', [HiringRequestController::class, 'decide'])
                ->name('api.v1.stores.hiring-requests.decide');
        });
});