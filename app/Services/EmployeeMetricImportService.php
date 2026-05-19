<?php

namespace App\Services;

use App\Models\EmployeeId;
use App\Models\EmployeeMetric;
use App\Models\EmployeeMetricColumn;
use App\Models\EmployeeMetricValue;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

class EmployeeMetricImportService
{
    private const HEADER_ALIASES = [
        'store' => ['store', 'store number', 'store#', 'store no', 'storenumber'],
        'date' => ['date', 'work date', 'pay date'],
        'id' => ['id', 'emp id', 'employee id', 'employee_id', 'empid'],
        'name' => ['name', 'employee name', 'employee'],
    ];

    /**
     * @param array<string, mixed> $options
     * @return array{summary: array<string, int>, unmatched: array<int, array<string, mixed>>, failures: array<int, array<string, mixed>>}
     */
    public function import(UploadedFile $file, array $options = []): array
    {
        $opts = array_merge([
            'id_type_id' => 1,
        ], $options);

        $idTypeId = (int) ($opts['id_type_id'] ?? 1);

        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        $csv = new SplFileObject($path);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headers = $this->readHeaders($csv);
        $headerMap = $this->resolveHeaderMap($headers);

        $idHeader = $headerMap['id'] ?? null;
        $dateHeader = $headerMap['date'] ?? null;
        $storeHeader = $headerMap['store'] ?? null;
        $nameHeader = $headerMap['name'] ?? null;

        if ($idHeader === null) {
            throw new RuntimeException('Missing required ID column in CSV header.');
        }

        if ($dateHeader === null) {
            throw new RuntimeException('Missing required Date column in CSV header.');
        }

        $excludeHeaders = array_filter([$idHeader, $nameHeader, $storeHeader, $dateHeader]);
        $columnMap = $this->resolveColumns($headers, $excludeHeaders);
        $employeeIdMap = $this->loadEmployeeIdMap($idTypeId);

        $summary = [
            'rows_total' => 0,
            'created' => 0,
            'updated' => 0,
            'unmatched' => 0,
            'failed' => 0,
        ];

        $unmatched = [];
        $failures = [];

        foreach ($this->rows($csv, $headers) as $line => $row) {
            $summary['rows_total']++;

            $idValue = $this->cleanValue($row[$idHeader] ?? null);
            if ($idValue === null) {
                $summary['failed']++;
                $failures[] = [
                    'line' => $line,
                    'error' => 'Missing ID value.',
                ];
                continue;
            }

            $employeeId = $employeeIdMap[$idValue] ?? null;
            if ($employeeId === null) {
                $summary['unmatched']++;
                $unmatched[] = [
                    'line' => $line,
                    'id_value' => $idValue,
                    'store_number' => $storeHeader ? $this->cleanValue($row[$storeHeader] ?? null) : null,
                    'date_raw' => $row[$dateHeader] ?? null,
                ];
                continue;
            }

            $metricDate = $this->parseMetricDate($row[$dateHeader] ?? null);
            if ($metricDate === null) {
                $summary['failed']++;
                $failures[] = [
                    'line' => $line,
                    'error' => 'Invalid date value.',
                    'id_value' => $idValue,
                ];
                continue;
            }

            $storeNumber = $storeHeader ? $this->cleanValue($row[$storeHeader] ?? null) : null;

            $wasCreated = false;

            DB::transaction(function () use ($employeeId, $metricDate, $storeNumber, $columnMap, $row, &$wasCreated): void {
                $metric = EmployeeMetric::query()->updateOrCreate([
                    'employee_id' => $employeeId,
                    'metric_date' => $metricDate,
                ], [
                    'store_number' => $storeNumber,
                ]);

                $wasCreated = $metric->wasRecentlyCreated;

                $values = [];
                $now = now();

                foreach ($columnMap as $header => $column) {
                    $rawValue = $this->cleanValue($row[$header] ?? null);
                    if ($rawValue === null) {
                        continue;
                    }

                    $values[] = [
                        'employee_metric_id' => $metric->id,
                        'column_id' => $column->id,
                        'value' => $rawValue,
                        'value_numeric' => $this->parseNumeric($rawValue),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                EmployeeMetricValue::query()
                    ->where('employee_metric_id', $metric->id)
                    ->delete();

                if ($values !== []) {
                    EmployeeMetricValue::query()->insert($values);
                }
            });

            if ($wasCreated) {
                $summary['created']++;
            } else {
                $summary['updated']++;
            }
        }

        return [
            'summary' => $summary,
            'unmatched' => $unmatched,
            'failures' => $failures,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function readHeaders(SplFileObject $csv): array
    {
        $csv->rewind();

        while (!$csv->eof()) {
            $record = $csv->fgetcsv();

            if ($record === [null] || $record === false) {
                continue;
            }

            $headers = [];
            $counts = [];

            foreach ($record as $index => $value) {
                $label = $this->normalizeLabel((string) $value);

                if ($label === '') {
                    $label = 'column_' . ($index + 1);
                }

                if (isset($counts[$label])) {
                    $counts[$label]++;
                    $label .= '_' . $counts[$label];
                } else {
                    $counts[$label] = 1;
                }

                $headers[] = $label;
            }

            return $headers;
        }

        throw new RuntimeException('CSV file is missing a header row.');
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, string>
     */
    private function resolveHeaderMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $header) {
            $normalized = $this->normalizeHeaderKey($header);

            foreach (self::HEADER_ALIASES as $key => $aliases) {
                foreach ($aliases as $alias) {
                    if ($normalized === $this->normalizeHeaderKey($alias)) {
                        $map[$key] = $map[$key] ?? $header;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string> $exclude
     * @return array<string, EmployeeMetricColumn>
     */
    private function resolveColumns(array $headers, array $exclude): array
    {
        $columns = EmployeeMetricColumn::query()->get()->keyBy('label');
        $existingKeys = [];

        foreach ($columns as $column) {
            $existingKeys[$column->key] = true;
        }

        $columnMap = [];

        foreach ($headers as $header) {
            if (in_array($header, $exclude, true)) {
                continue;
            }

            if (isset($columnMap[$header])) {
                continue;
            }

            $column = $columns->get($header);

            if (!$column) {
                $key = $this->buildUniqueKey($header, $existingKeys);
                $column = EmployeeMetricColumn::query()->create([
                    'label' => $header,
                    'key' => $key,
                ]);
                $columns->put($header, $column);
                $existingKeys[$key] = true;
            }

            $columnMap[$header] = $column;
        }

        return $columnMap;
    }

    /**
     * @return array<string, int>
     */
    private function loadEmployeeIdMap(int $idTypeId): array
    {
        $rows = EmployeeId::query()
            ->where('id_type_id', $idTypeId)
            ->get(['id_value', 'employee_id']);

        $map = [];

        foreach ($rows as $row) {
            $value = $this->cleanValue($row->id_value);
            if ($value === null) {
                continue;
            }

            $map[$value] = (int) $row->employee_id;
        }

        return $map;
    }

    /**
     * @param array<int, string> $headers
     * @return \Generator<int, array<string, string>>
     */
    private function rows(SplFileObject $csv, array $headers): \Generator
    {
        $csv->rewind();
        $line = 0;
        $skipHeader = true;

        foreach ($csv as $record) {
            $line++;

            if ($record === [null] || $record === false) {
                continue;
            }

            $record = array_map(function ($value) {
                if ($value === null) {
                    return '';
                }

                return trim((string) $value);
            }, $record);

            if ($skipHeader) {
                $skipHeader = false;
                continue;
            }

            if (count(array_filter($record, fn(string $v) => $v !== '')) === 0) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $record[$index] ?? '';
            }

            yield $line => $row;
        }
    }

    private function normalizeLabel(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return $value;
    }

    private function normalizeHeaderKey(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim($value);
    }

    private function cleanValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseMetricDate(mixed $value): ?string
    {
        $raw = $this->cleanValue($value);

        if ($raw === null) {
            return null;
        }

        if (is_numeric($raw)) {
            $days = (int) round((float) $raw);
            if ($days <= 0) {
                return null;
            }

            return Carbon::create(1899, 12, 30)->addDays($days)->toDateString();
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseNumeric(string $value): ?string
    {
        $normalized = trim($value);
        $normalized = str_replace([',', '$'], '', $normalized);
        $normalized = preg_replace('/^\((.*)\)$/', '-$1', $normalized);

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;
        $formatted = rtrim(rtrim(sprintf('%.12F', $number), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param array<string, bool> $existingKeys
     */
    private function buildUniqueKey(string $label, array $existingKeys): string
    {
        $key = Str::slug($label, '_');
        $key = $key !== '' ? $key : 'column';
        $base = $key;
        $suffix = 2;

        while (isset($existingKeys[$key])) {
            $key = $base . '_' . $suffix;
            $suffix++;
        }

        return $key;
    }
}
