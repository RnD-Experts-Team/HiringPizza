<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every status change (hired, resigned, terminated, rehired) on its effective
 * date, at the store it was recorded for; `count` counts them. Notes are free
 * text and are not offered.
 */
class StatusChangesDataset extends ReportDataset
{
    public const KEY = 'hiring.status_changes';

    private const STATUSES = ['hired' => 'Hired', 'resigned' => 'Resigned', 'terminated' => 'Terminated', 'rehired' => 'Rehired'];

    public function label(): string
    {
        return 'Employee status changes';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        $statuses = array_map(
            fn ($value, $label) => ['value' => (string) $value, 'label' => $label],
            array_keys(self::STATUSES),
            self::STATUSES,
        );

        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Effective date', 'date', 'employee_status_histories.effective_date', groupable: true),
            new Column('employee', 'Employee', 'integer', 'employee_status_histories.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('status', 'Status', 'enum', 'employee_status_histories.status', options: $statuses, groupable: true),
            new Column('count', 'Status changes', 'integer', 'employee_status_histories.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('employee_status_histories')
            ->leftJoin('stores', 'stores.id', '=', 'employee_status_histories.store_id');
    }

    public function storeColumn(): string
    {
        return 'employee_status_histories.store_id';
    }

    public function dateColumn(): string
    {
        return 'employee_status_histories.effective_date';
    }
}
