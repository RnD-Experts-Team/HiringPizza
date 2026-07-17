<?php

namespace App\Services;

use App\Models\EmployeeMetric;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EmployeeMetricQueryService
{
    public function index(array $filters): LengthAwarePaginator
    {
        $query = EmployeeMetric::query()
            ->with(['values.column']);

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        $perPage = min((int) ($filters['per_page'] ?? 25), 200);

        $metrics = $query->paginate($perPage)->withQueryString();
        $metrics->setCollection(
            $metrics->getCollection()->map(fn(EmployeeMetric $metric) => $this->mapMetric($metric))
        );

        return $metrics;
    }

    /**
     * Operational history for a single employee: every metric/column value ever
     * recorded for them (from the start of time until now), flattened into
     * { column_name, value } entries like the manager dashboard.
     *
     * Returns a LengthAwarePaginator when $filters['paginated'] is truthy,
     * otherwise the full history as a plain array.
     *
     * @return array<int, array<string, mixed>>|LengthAwarePaginator
     */
    public function operationalForEmployee(int $employeeId, array $filters): array|LengthAwarePaginator
    {
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = EmployeeMetric::query()
            ->from('employee_metrics as em')
            ->join('employee_metric_values as emv', 'emv.employee_metric_id', '=', 'em.id')
            ->join('employee_metric_columns as emc', 'emc.id', '=', 'emv.column_id')
            ->where('em.employee_id', $employeeId)
            ->when($filters['date_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('em.metric_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('em.metric_date', '<=', $v))
            ->select([
                'em.id as metric_id',
                'em.metric_date',
                'em.store_number',
                'emc.id as column_id',
                'emc.key as column_key',
                'emc.label as column_name',
                'emv.value',
                'emv.value_numeric',
            ])
            ->orderBy('em.metric_date', $sortDir)
            ->orderBy('emc.id');

        $paginated = filter_var($filters['paginated'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($paginated) {
            $perPage = min((int) ($filters['per_page'] ?? 25), 200);

            $entries = $query->paginate($perPage)->withQueryString();
            $entries->setCollection(
                $entries->getCollection()->map(fn($row) => $this->mapOperationalEntry($row))
            );

            return $entries;
        }

        return $query->get()
            ->map(fn($row) => $this->mapOperationalEntry($row))
            ->all();
    }

    private function mapOperationalEntry($row): array
    {
        return [
            'metric_date' => $row->metric_date instanceof \Carbon\CarbonInterface
                ? $row->metric_date->toDateString()
                : (string) $row->metric_date,
            'store_number' => $row->store_number,
            'column_name' => $row->column_name,
            'column_key' => $row->column_key,
            'value' => $row->value,
            'value_numeric' => $row->value_numeric !== null ? (float) $row->value_numeric : null,
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['employee_id'] ?? null, fn(Builder $q, $v) => $q->where('employee_id', $v))
            ->when($filters['store_number'] ?? null, fn(Builder $q, $v) => $q->where('store_number', $v))
            ->when($filters['date_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('metric_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('metric_date', '<=', $v));

        if (!empty($filters['emp_id_value'])) {
            $idTypeId = (int) ($filters['id_type_id'] ?? 1);
            $empIdValue = (string) $filters['emp_id_value'];

            $query->whereHas('employee.ids', function (Builder $q) use ($idTypeId, $empIdValue) {
                $q->where('id_type_id', $idTypeId)
                    ->where('id_value', $empIdValue);
            });
        }

        $hasColumnFilter = !empty($filters['column_key'])
            || !empty($filters['column_label'])
            || array_key_exists('value', $filters)
            || array_key_exists('value_min', $filters)
            || array_key_exists('value_max', $filters);

        if ($hasColumnFilter) {
            $query->whereHas('values', function (Builder $q) use ($filters) {
                if (!empty($filters['column_key']) || !empty($filters['column_label'])) {
                    $q->whereHas('column', function (Builder $c) use ($filters) {
                        if (!empty($filters['column_key'])) {
                            $c->where('key', $filters['column_key']);
                        }

                        if (!empty($filters['column_label'])) {
                            $c->where('label', $filters['column_label']);
                        }
                    });
                }

                if (array_key_exists('value', $filters)) {
                    $q->where('value', $filters['value']);
                }

                if (array_key_exists('value_min', $filters)) {
                    $q->where('value_numeric', '>=', $filters['value_min']);
                }

                if (array_key_exists('value_max', $filters)) {
                    $q->where('value_numeric', '<=', $filters['value_max']);
                }
            });
        }
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'metric_date';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowed = ['metric_date', 'created_at', 'updated_at', 'employee_id', 'store_number'];

        if (!in_array($sortBy, $allowed, true)) {
            $sortBy = 'metric_date';
        }

        $query->orderBy($sortBy, $sortDir);
    }

    private function mapMetric(EmployeeMetric $metric): array
    {
        return [
            'id' => $metric->id,
            'employee_id' => $metric->employee_id,
            'metric_date' => $metric->metric_date instanceof \Carbon\CarbonInterface
                ? $metric->metric_date->toDateString()
                : (string) $metric->metric_date,
            'store_number' => $metric->store_number,
            'created_at' => $metric->created_at,
            'updated_at' => $metric->updated_at,
            'values' => $metric->values
                ->mapWithKeys(function ($value) {
                    return [
                        $value->column->key => [
                            'label' => $value->column->label,
                            'value' => $value->value,
                            'value_numeric' => $value->value_numeric,
                        ],
                    ];
                })
                ->all(),
        ];
    }
}
