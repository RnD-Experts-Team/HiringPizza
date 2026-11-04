<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pay rates as entered, on the date they took effect. Pay history has no store
 * of its own, so a row belongs to the store of the employee's latest assignment
 * (the store the employee endpoints show the pay history under). Its own
 * dataset so it has its own pizzasys rule.
 */
class PayDataset extends ReportDataset
{
    public const KEY = 'hiring.pay';

    private const SUMS = ['sum', 'avg', 'min', 'max'];

    public function label(): string
    {
        return 'Employee pay history';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Effective date', 'date', 'employee_pay_histories.effective_date', groupable: true),
            new Column('employee', 'Employee', 'integer', 'employee_pay_histories.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('base_pay', 'Base pay', 'money', 'employee_pay_histories.base_pay', measure: true, aggregations: self::SUMS),
            new Column('performance_pay', 'Performance pay', 'money', 'employee_pay_histories.performance_pay', measure: true, aggregations: self::SUMS),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('employee_pay_histories')
            ->join('employee_stores as es', 'es.employee_id', '=', 'employee_pay_histories.employee_id')
            ->join('stores', 'stores.id', '=', 'es.store_id')
            ->whereRaw('es.id = (select newest.id from employee_stores as newest where newest.employee_id = es.employee_id order by newest.effective_date desc, newest.id desc limit 1)');
    }

    public function storeColumn(): string
    {
        return 'es.store_id';
    }

    public function dateColumn(): string
    {
        return 'employee_pay_histories.effective_date';
    }
}
