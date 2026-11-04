<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Imported employee metrics (one row per employee, store and day), with one
 * report column per metric column. The metric columns are rows of
 * employee_metric_columns, created by the CSV import, so columns() is built
 * at runtime.
 *
 * - Key: `m_<id>` (the metric column's id). A label is free to change; the key
 *   never does, so saved report templates keep working.
 * - Type: the table stores no type. A column is a number when every value
 *   imported for it parsed as one (value_numeric is set), otherwise text.
 *   Numbers are aggregated (sum, avg, min, max); text can be grouped.
 * - Pivot: baseQuery() turns the long employee_metric_values rows into one
 *   wide row per metric with conditional aggregation
 *   (MAX(CASE WHEN column_id = <id> THEN value END)). The ids are integers read
 *   from the table and written as literals.
 * - Store: the metrics hold the store code as imported (`store_number`), so the
 *   store filter compares it directly.
 */
class MetricsDataset extends ReportDataset
{
    public const KEY = 'hiring.metrics';

    private const NUMBER_AGGREGATIONS = ['sum', 'avg', 'min', 'max'];

    /** @var list<array{id: int, label: string, numeric: bool}>|null */
    private ?array $metricColumns = null;

    public function label(): string
    {
        return 'Employee metrics';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        $columns = [
            new Column('store', 'Store', 'string', 'metrics.store_number', groupable: true),
            new Column('date', 'Metric date', 'date', 'metrics.metric_date', groupable: true),
            new Column('employee', 'Employee', 'integer', 'metrics.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('count', 'Metric rows', 'integer', 'metrics.metric_id', filterable: false, measure: true, aggregations: ['count']),
        ];

        foreach ($this->metricColumns() as $metric) {
            $key = self::key($metric['id']);
            $columns[] = $metric['numeric']
                ? new Column($key, $metric['label'], 'number', 'metrics.'.$key, measure: true, aggregations: self::NUMBER_AGGREGATIONS)
                : new Column($key, $metric['label'], 'string', 'metrics.'.$key, groupable: true);
        }

        return $columns;
    }

    public function baseQuery(): Builder
    {
        $pivot = DB::table('employee_metrics as em')
            ->leftJoin('employee_metric_values as v', 'v.employee_metric_id', '=', 'em.id')
            ->groupBy('em.id', 'em.employee_id', 'em.metric_date', 'em.store_number')
            ->selectRaw('em.id as metric_id, em.employee_id, em.metric_date, em.store_number');

        foreach ($this->metricColumns() as $metric) {
            $field = $metric['numeric'] ? 'value_numeric' : 'value';
            $pivot->selectRaw(sprintf('MAX(CASE WHEN v.column_id = %d THEN v.%s END) as %s', $metric['id'], $field, self::key($metric['id'])));
        }

        return DB::query()->fromSub($pivot, 'metrics');
    }

    public function storeColumn(): string
    {
        return 'metrics.store_number';
    }

    /** The metrics keep the store code itself. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }

    public function dateColumn(): ?string
    {
        return 'metrics.metric_date';
    }

    public function heavy(): bool
    {
        return true;
    }

    public static function key(int $columnId): string
    {
        return 'm_'.$columnId;
    }

    /** @return list<array{id: int, label: string, numeric: bool}> */
    private function metricColumns(): array
    {
        // One cheap probe per column: is there a value that did not parse as a number?
        return $this->metricColumns ??= DB::table('employee_metric_columns as c')
            ->orderBy('c.id')
            ->selectRaw('c.id, c.label, EXISTS (SELECT 1 FROM employee_metric_values v WHERE v.column_id = c.id AND v.value_numeric IS NULL AND v.value IS NOT NULL) as has_text')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) $row->label,
                // A column nothing was imported for yet is offered as a number.
                'numeric' => ! (bool) $row->has_text,
            ])
            ->all();
    }
}
