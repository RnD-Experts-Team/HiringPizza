<?php

use App\Http\Controllers\Api\V1\EmployeeWorkflowController;
use App\Http\Controllers\Api\V1\EmployeeMetricController;
use App\Http\Controllers\Api\V1\LaborController;
use App\Http\Controllers\Api\V1\ReferenceCatalogController;
use App\Http\Controllers\Api\V1\SeparationRequestController;
use App\Http\Controllers\Api\V1\HiringRequestController;
use App\Http\Controllers\Api\V1\ManagerDashboardController;
use App\Http\Controllers\Api\V1\ShirtCatalogController;
use App\Http\Controllers\Api\V1\ShirtMilestoneController;
use App\Http\Controllers\Api\V1\WorkflowRequestController;
use Illuminate\Support\Facades\Route;

/*
| Rate limits (AppServiceProvider): `throttle:api` sits next to every
| auth.token.store and runs ahead of the pizzasys call. Reports add their own
| tier on top; the auth.secret.key exports are counted per secret + IP.
*/

Route::get('employees/export/csv', [EmployeeWorkflowController::class, 'export'])
    ->name('api.v1.stores.employees.export')->middleware(['throttle:service-secret', 'throttle:api-export', 'auth.secret.key']);

Route::get('/average-hourly-pay/{store}/{date}', [ManagerDashboardController::class, 'averageHourlyPay'])
    ->middleware(['throttle:api', 'throttle:api-heavy', 'auth.token.store']);

Route::get('high-hours-employees/{store}/{date}', [ManagerDashboardController::class, 'highHoursEmployees'])
    ->middleware(['throttle:api', 'throttle:api-heavy', 'auth.token.store']);

Route::get('employees/tenure/export/csv', [EmployeeWorkflowController::class, 'exportTenure'])
    ->name('api.v1.employees.tenure.export')->middleware(['throttle:service-secret', 'throttle:api-export', 'auth.secret.key']);

Route::get('employees/separation-statuses/export/csv', [EmployeeWorkflowController::class, 'exportSeparationStatusHistory'])
    ->name('api.v1.employees.separation-statuses.export')->middleware(['throttle:service-secret', 'throttle:api-export', 'auth.secret.key']);

Route::get('employees/hiring-temp-bernard/export/csv', [EmployeeWorkflowController::class, 'exportHiringTempBernard'])->name('api.v1.employees.hiring-temp-bernard.export')->middleware(['throttle:service-secret', 'throttle:api-export', 'auth.secret.key']);

Route::prefix('v1')->middleware(['throttle:api', 'auth.token.store'])->group(function (): void {
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
        ->middleware('throttle:api-heavy-multi')
        ->name('api.v1.reports.multi');

    // Combined manager-dashboard page report (one call instead of three).
    Route::get('reports/{store}/{date}', [ManagerDashboardController::class, 'reports'])
        ->where(['date' => '[0-9]{4}-[0-9]{2}-[0-9]{2}'])
        ->middleware('throttle:api-heavy')
        ->name('api.v1.reports.show');

    Route::get('employees', [EmployeeWorkflowController::class, 'indexGlobal'])
        ->name('api.v1.employees.index');

    Route::get('requests', [WorkflowRequestController::class, 'indexGlobal'])
        ->name('api.v1.requests.index');

    // Employee Shirt Milestones — the store manager's queue across several
    // stores in one call (storeIds[] = store numbers), same as requests above.
    // Deliberately NOT under shirt-milestones: that name is the fulfilment
    // side's, which the auth server grants by role, not per store.
    Route::get('store-shirt-milestones', [ShirtMilestoneController::class, 'indexForStores'])
        ->name('api.v1.store-shirt-milestones.index');
    // Unified calendar feed. Stores are in the query (stores=a,b), not the path:
    // the pizzasys rule must read store_id_sources.query ["stores"] with policy "all".
    Route::get('calendar/feed', \App\Http\Controllers\Api\V1\CalendarFeedController::class)
        ->name('api.v1.calendar.feed');

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
                ->middleware('throttle:api-heavy')
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
                ->middleware('throttle:api-heavy')
                ->name('api.v1.stores.labor.show');

            // Hiring Request Workflow
            Route::post('hiring-requests', [HiringRequestController::class, 'store'])
                ->name('api.v1.stores.hiring-requests.store');

            Route::post('hiring-requests/{hiringRequest}/decision', [HiringRequestController::class, 'decide'])
                ->name('api.v1.stores.hiring-requests.decide');

            // Employee Shirt Milestones — the store manager's side.
            // Every completed month of tenure opens one of these; the manager
            // fills in the shirt form and it moves on to the fulfilment queue.
            Route::get('shirt-milestones', [ShirtMilestoneController::class, 'index'])
                ->name('api.v1.stores.shirt-milestones.index');

            // Create and fill in one call, for giving someone a shirt without
            // waiting for a month to come around.
            Route::post('shirt-milestones', [ShirtMilestoneController::class, 'store'])
                ->name('api.v1.stores.shirt-milestones.store');

            Route::get('shirt-milestones/{shirtMilestone}', [ShirtMilestoneController::class, 'show'])
                ->whereNumber('shirtMilestone')
                ->name('api.v1.stores.shirt-milestones.show');

            Route::post('shirt-milestones/{shirtMilestone}/entry', [ShirtMilestoneController::class, 'entry'])
                ->whereNumber('shirtMilestone')
                ->name('api.v1.stores.shirt-milestones.entry');

            // How many shirts this employee has received so far, plus detail.
            Route::get('employees/{employee}/shirts', [ShirtMilestoneController::class, 'employeeHistory'])
                ->whereNumber('employee')
                ->name('api.v1.stores.employees.shirts');
        });

    // Employee Shirt Milestones — the fulfilment side, which works across every
    // store. Authorization is the auth server's call, same as every other route
    // here: auth.token.store sends it the route name and method and requires
    // ext.authorized back.
    Route::get('shirt-milestones', [ShirtMilestoneController::class, 'indexGlobal'])
        ->name('api.v1.shirt-milestones.index');

    Route::get('shirt-milestones/{shirtMilestone}', [ShirtMilestoneController::class, 'showGlobal'])
        ->whereNumber('shirtMilestone')
        ->name('api.v1.shirt-milestones.show');

    Route::post('shirt-milestones/{shirtMilestone}/order', [ShirtMilestoneController::class, 'order'])
        ->whereNumber('shirtMilestone')
        ->name('api.v1.shirt-milestones.order');

    Route::patch('shirt-milestones/{shirtMilestone}/delivery-date', [ShirtMilestoneController::class, 'updateDeliveryDate'])
        ->whereNumber('shirtMilestone')
        ->name('api.v1.shirt-milestones.delivery-date');

    Route::post('shirt-milestones/{shirtMilestone}/deliver', [ShirtMilestoneController::class, 'deliver'])
        ->whereNumber('shirtMilestone')
        ->name('api.v1.shirt-milestones.deliver');

    Route::post('shirt-milestones/{shirtMilestone}/cancel', [ShirtMilestoneController::class, 'cancel'])
        ->whereNumber('shirtMilestone')
        ->name('api.v1.shirt-milestones.cancel');

    // The single catalogue read: colours, logos and templates in one call, for
    // the entry form and its live preview. Pass ?include_inactive=1 for the
    // management view, which also needs the deactivated rows.
    Route::get('shirt-catalog', [ShirtCatalogController::class, 'catalog'])
        ->name('api.v1.shirt-catalog.index');

    // Catalogue management. DELETE deactivates, never removes — milestones
    // reference these rows historically.
    Route::post('shirt-colors', [ShirtCatalogController::class, 'storeColor'])
        ->name('api.v1.shirt-colors.store');
    Route::put('shirt-colors/{color}', [ShirtCatalogController::class, 'updateColor'])
        ->whereNumber('color')->name('api.v1.shirt-colors.update');
    Route::delete('shirt-colors/{color}', [ShirtCatalogController::class, 'destroyColor'])
        ->whereNumber('color')->name('api.v1.shirt-colors.destroy');

    Route::post('shirt-logos', [ShirtCatalogController::class, 'storeLogo'])
        ->name('api.v1.shirt-logos.store');
    Route::post('shirt-logos/{logo}', [ShirtCatalogController::class, 'updateLogo'])
        ->whereNumber('logo')->name('api.v1.shirt-logos.update');
    Route::delete('shirt-logos/{logo}', [ShirtCatalogController::class, 'destroyLogo'])
        ->whereNumber('logo')->name('api.v1.shirt-logos.destroy');

    Route::post('shirt-templates', [ShirtCatalogController::class, 'storeTemplate'])
        ->name('api.v1.shirt-templates.store');
    Route::post('shirt-templates/{template}', [ShirtCatalogController::class, 'updateTemplate'])
        ->whereNumber('template')->name('api.v1.shirt-templates.update');
    Route::delete('shirt-templates/{template}', [ShirtCatalogController::class, 'destroyTemplate'])
        ->whereNumber('template')->name('api.v1.shirt-templates.destroy');
});