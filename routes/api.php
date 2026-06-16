<?php

use App\Http\Controllers\Api\V1\EmployeeWorkflowController;
use App\Http\Controllers\Api\V1\EmployeeMetricController;
use App\Http\Controllers\Api\V1\ReferenceCatalogController;
use App\Http\Controllers\Api\V1\SeparationRequestController;
use App\Http\Controllers\Api\V1\HiringRequestController;
use App\Http\Controllers\Api\V1\ManagerDashboardController;
use App\Http\Controllers\Api\V1\WorkflowRequestController;
use Illuminate\Support\Facades\Route;


Route::get('employees/export/csv', [EmployeeWorkflowController::class, 'export'])
    ->name('api.v1.stores.employees.export')->middleware('auth.secret.key');

Route::get('/average-hourly-pay/{store}/{date}', [ManagerDashboardController::class, 'averageHourlyPay'])
    ->middleware('auth.token.store');

Route::get('high-hours-employees/{store}/{date}', [ManagerDashboardController::class, 'highHoursEmployees'])
    ->middleware('auth.token.store');

Route::get('employees/tenure/export/csv', [EmployeeWorkflowController::class, 'exportTenure'])
    ->name('api.v1.employees.tenure.export')->middleware('auth.secret.key');

Route::get('employees/separation-statuses/export/csv', [EmployeeWorkflowController::class, 'exportSeparationStatusHistory'])
    ->name('api.v1.employees.separation-statuses.export')->middleware('auth.secret.key');

Route::get('employees/hiring-temp-bernard/export/csv', [EmployeeWorkflowController::class, 'exportHiringTempBernard'])->name('api.v1.employees.hiring-temp-bernard.export')->middleware('auth.secret.key');

Route::prefix('v1')->middleware('auth.token.store')->group(function (): void {
    Route::post('employee-metrics/import', [EmployeeMetricController::class, 'import'])
        ->name('api.v1.employee-metrics.import');

    Route::get('employee-metrics', [EmployeeMetricController::class, 'index'])
        ->name('api.v1.employee-metrics.index');

    Route::get('reference-catalog', [ReferenceCatalogController::class, 'index'])
        ->name('api.v1.reference-catalog.index');

    Route::put('reference-catalog', [ReferenceCatalogController::class, 'sync'])
        ->name('api.v1.reference-catalog.sync');

    // Combined manager-dashboard page report (one call instead of three).
    Route::get('reports/{store}/{date}', [ManagerDashboardController::class, 'reports'])
        ->where(['date' => '[0-9]{4}-[0-9]{2}-[0-9]{2}'])
        ->name('api.v1.reports.show');

    Route::prefix('stores/{storeId}')
        ->where(['storeId' => '[A-Za-z0-9_-]+'])
        ->group(function (): void {
            Route::get('employees', [EmployeeWorkflowController::class, 'index'])
                ->name('api.v1.stores.employees.index');

            Route::post('employees', [EmployeeWorkflowController::class, 'store'])
                ->name('api.v1.stores.employees.store');

            Route::get('employees/{employee}', [EmployeeWorkflowController::class, 'show'])
                ->name('api.v1.stores.employees.show');

            Route::post('employees/{employee}', [EmployeeWorkflowController::class, 'update'])
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

            // Manager Dashboard
            Route::get('manager-dashboard/{date}', [ManagerDashboardController::class, 'show'])
                ->where(['date' => '[0-9]{4}-[0-9]{2}-[0-9]{2}'])
                ->name('api.v1.stores.manager-dashboard.show');

            // Hiring Request Workflow
            Route::post('hiring-requests', [HiringRequestController::class, 'store'])
                ->name('api.v1.stores.hiring-requests.store');

            Route::post('hiring-requests/{hiringRequest}/decision', [HiringRequestController::class, 'decide'])
                ->name('api.v1.stores.hiring-requests.decide');
        });
});