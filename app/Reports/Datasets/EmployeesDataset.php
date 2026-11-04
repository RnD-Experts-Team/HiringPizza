<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use App\Reports\Options;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The current roster, a snapshot: one row per employee, at the store of their
 * latest assignment, with their latest status and position. It ignores the
 * report period. Status, position and store are history tables (the newest
 * effective_date wins, ties broken by id).
 *
 * Deliberately has no SSN, birth date, address, contact or bank columns.
 */
class EmployeesDataset extends ReportDataset
{
    public const KEY = 'hiring.employees';

    public function label(): string
    {
        return 'Employees (current roster)';
    }

    public function grain(): array
    {
        return ['store', 'employee'];
    }

    public function columns(): array
    {
        $positions = DB::table('positions')->orderBy('label')->pluck('label')
            ->map(fn ($label) => ['value' => $label, 'label' => $label])
            ->all();

        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('employee', 'Employee', 'integer', 'employees.id', groupable: true, aggregations: ['count_distinct']),
            new Column('name', 'Name', 'string', "CONCAT(employees.first_name, ' ', employees.last_name)", groupable: true),
            new Column('position', 'Position', 'enum', 'positions.label', options: $positions, groupable: true),
            new Column('status', 'Status', 'enum', 'current_status.status', options: Options::of(EmployeeStatus::class), groupable: true),
            new Column('employment_type', 'Employment type', 'enum', 'employees.employment_type', options: Options::of(EmploymentType::class), groupable: true),
            new Column('hire_date', 'Hire date', 'date', 'hire.hire_date', groupable: true),
            new Column('tenure_days', 'Tenure (days since hire)', 'integer', $this->tenureDays(), aggregations: ['avg', 'min', 'max']),
            new Column('status_date', 'Status effective date', 'date', 'current_status.effective_date', groupable: true),
            new Column('is_active', 'Active', 'boolean', "CASE WHEN current_status.status IN ('resigned', 'terminated') THEN 0 ELSE 1 END", groupable: true),
            new Column('count', 'Employees', 'integer', 'employees.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    /** hire_date is the first `hired` entry; a rehire does not move it. */
    public function baseQuery(): Builder
    {
        $firstHired = DB::table('employee_status_histories')
            ->where('status', 'hired')
            ->groupBy('employee_id')
            ->selectRaw('employee_id, MIN(effective_date) as hire_date');

        return DB::table('employee_stores as es')
            ->join('stores', 'stores.id', '=', 'es.store_id')
            ->join('employees', 'employees.id', '=', 'es.employee_id')
            ->leftJoin('employee_status_histories as current_status', fn ($join) => $join
                ->on('current_status.employee_id', '=', 'employees.id')
                ->whereRaw($this->latest('current_status', 'employee_status_histories')))
            ->leftJoin('employee_positions as current_position', fn ($join) => $join
                ->on('current_position.employee_id', '=', 'employees.id')
                ->whereRaw($this->latest('current_position', 'employee_positions')))
            ->leftJoin('positions', 'positions.id', '=', 'current_position.position_id')
            ->leftJoinSub($firstHired, 'hire', 'hire.employee_id', '=', 'employees.id')
            ->whereRaw($this->latest('es', 'employee_stores'));
    }

    public function storeColumn(): string
    {
        return 'es.store_id';
    }

    public function dateColumn(): ?string
    {
        return null;
    }

    public function heavy(): bool
    {
        return true;
    }

    /** Whole days from the hire date to today (the app's date, written into the SQL as a literal). */
    private function tenureDays(): string
    {
        $today = Carbon::today()->toDateString();

        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(julianday('{$today}') - julianday(hire.hire_date) AS INTEGER)"
            : "DATEDIFF('{$today}', hire.hire_date)";
    }

    /** The newest row of a history table per employee (the rule EmployeeQueryService uses). */
    private function latest(string $alias, string $table): string
    {
        return "{$alias}.id = (select newest.id from {$table} as newest where newest.employee_id = {$alias}.employee_id order by newest.effective_date desc, newest.id desc limit 1)";
    }
}
