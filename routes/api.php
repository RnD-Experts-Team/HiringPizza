<?php

use App\Http\Controllers\Api\V1\EmployeeWorkflowController;
use App\Http\Controllers\Api\V1\ReferenceCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
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

        });
});