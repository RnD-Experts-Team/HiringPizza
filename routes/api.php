<?php

use App\Http\Controllers\Api\V1\EmployeeWorkflowController;
use App\Http\Controllers\Api\V1\EmployeeMetricController;
use App\Http\Controllers\Api\V1\LaborController;
use App\Http\Controllers\Api\V1\ReferenceCatalogController;
use App\Http\Controllers\Api\V1\SeparationRequestController;
use App\Http\Controllers\Api\V1\HiringRequestController;
use App\Http\Controllers\Api\V1\ManagerDashboardController;
use App\Http\Controllers\Api\V1\MilestoneGiftQuestionController;
use App\Http\Controllers\Api\V1\MilestoneGiftRequestController;
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

    // Multi-store date-range report: stores[] query param (or stores[]=all), start_date, end_date.
    Route::get('reports', [ManagerDashboardController::class, 'reportsMultiStore'])
        ->name('api.v1.reports.multi');

    // Combined manager-dashboard page report (one call instead of three).
    Route::get('reports/{store}/{date}', [ManagerDashboardController::class, 'reports'])
        ->where(['date' => '[0-9]{4}-[0-9]{2}-[0-9]{2}'])
        ->name('api.v1.reports.show');

    // Global milestone gift question management (no store prefix)
    Route::get('milestone-gift-questions', [MilestoneGiftQuestionController::class, 'indexAll'])
        ->name('api.v1.milestone-gift-questions.index');

    Route::post('milestone-gift-questions', [MilestoneGiftQuestionController::class, 'store'])
        ->name('api.v1.milestone-gift-questions.store');

    Route::put('milestone-gift-questions/{question}', [MilestoneGiftQuestionController::class, 'update'])
        ->name('api.v1.milestone-gift-questions.update');

    Route::delete('milestone-gift-questions/{question}', [MilestoneGiftQuestionController::class, 'destroy'])
        ->name('api.v1.milestone-gift-questions.destroy');

    Route::post('milestone-gift-questions/{question}/options', [MilestoneGiftQuestionController::class, 'storeOption'])
        ->name('api.v1.milestone-gift-questions.options.store');

    Route::put('milestone-gift-questions/{question}/options/{option}', [MilestoneGiftQuestionController::class, 'updateOption'])
        ->name('api.v1.milestone-gift-questions.options.update');

    Route::delete('milestone-gift-questions/{question}/options/{option}', [MilestoneGiftQuestionController::class, 'destroyOption'])
        ->name('api.v1.milestone-gift-questions.options.destroy');

    Route::get('employees', [EmployeeWorkflowController::class, 'indexGlobal'])
        ->name('api.v1.employees.index');

    Route::get('requests', [WorkflowRequestController::class, 'indexGlobal'])
        ->name('api.v1.requests.index');

    Route::prefix('stores/{storeId}')
        ->where(['storeId' => '[A-Za-z0-9_-]+'])
        ->group(function (): void {
            Route::get('employees', [EmployeeWorkflowController::class, 'index'])
                ->name('api.v1.stores.employees.index');

            Route::post('employees', [EmployeeWorkflowController::class, 'store'])
                ->name('api.v1.stores.employees.store');

            Route::get('employees/{employee}', [EmployeeWorkflowController::class, 'show'])
                ->name('api.v1.stores.employees.show');

            // Full employee info + operational history (all metric/column values
            // ever recorded for the employee). Pass ?paginated=1 to paginate.
            Route::get('employees/{employee}/operational', [EmployeeWorkflowController::class, 'operational'])
                ->name('api.v1.stores.employees.operational');

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

            // Labor report: store-wide headcount/tenure/turnover/labor snapshot for the
            // business week containing {date}, plus trailing-week trend context.
            // Override the trend window with ?trend_weeks= (default 6, max 12).
            Route::get('labor/{date}', [LaborController::class, 'show'])
                ->where(['date' => '[0-9]{4}-[0-9]{2}-[0-9]{2}'])
                ->name('api.v1.stores.labor.show');

            // Hiring Request Workflow
            Route::post('hiring-requests', [HiringRequestController::class, 'store'])
                ->name('api.v1.stores.hiring-requests.store');

            Route::post('hiring-requests/{hiringRequest}/decision', [HiringRequestController::class, 'decide'])
                ->name('api.v1.stores.hiring-requests.decide');

            // Milestone Gift Request Workflow
            Route::post('milestone-gift-requests', [MilestoneGiftRequestController::class, 'store'])
                ->name('api.v1.stores.milestone-gift-requests.store');

            Route::post('milestone-gift-requests/{milestoneGiftRequest}/rating', [MilestoneGiftRequestController::class, 'submitRating'])
                ->name('api.v1.stores.milestone-gift-requests.rating');

            Route::post('milestone-gift-requests/{milestoneGiftRequest}/gift-decision', [MilestoneGiftRequestController::class, 'recordDecision'])
                ->name('api.v1.stores.milestone-gift-requests.gift-decision');

            Route::post('milestone-gift-requests/{milestoneGiftRequest}/final-status', [MilestoneGiftRequestController::class, 'setFinalStatus'])
                ->name('api.v1.stores.milestone-gift-requests.final-status');

            // Milestone Gift Questions — store-scoped listing only (management is global, see above)
            Route::get('milestone-gift-questions', [MilestoneGiftQuestionController::class, 'index'])
                ->name('api.v1.stores.milestone-gift-questions.index');
        });
});