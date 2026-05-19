<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use RuntimeException;
use SplFileObject;
use Throwable;

class EmployeeSeparationDateFixService
{
    private const HEADER_ALIASES = [
        'store' => 'Store number',
        'storenumber' => 'Store number',
        'store#' => 'Store number',
        'storenum' => 'Store number',
        'name' => 'Name',
        'fullname' => 'Name',
        'empname' => 'Name',
        'ssn' => 'SSN',
        'ssnnumber' => 'SSN',
        'ssn_number' => 'SSN',
        'sex' => 'Sex',
        'gender' => 'Sex',
        'birthdate' => 'Birth Date',
        'birth_date' => 'Birth Date',
        'terminationdate' => 'Termination Date',
        'terminateddate' => 'Termination Date',
        'resigneddate' => 'Termination Date',
    ];

    /**
     * @return array{summary: array<string, int>, failures: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function apply(string $path, array $options = []): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("CSV file does not exist: {$path}");
        }

        $opts = array_merge([
            'dry_run' => false,
            'allow_name_only' => false,
        ], $options);

        $storeCache = [];
        $employeeCache = [];
        $warnings = [];
        $failures = [];
        $summary = [
            'rows_total' => 0,
            'updated' => 0,
            'skipped' => 0,
            'not_found' => 0,
            'missing_date' => 0,
            'status_mismatch' => 0,
            'failed' => 0,
        ];

        foreach ($this->rows($path) as $line => $row) {
            $summary['rows_total']++;

            try {
                $storeNumber = $this->required($row, 'Store number', $line);
                $store = $this->resolveStore($storeNumber, $storeCache);

                $terminationDate = $this->normalizeDate($row['Termination Date'] ?? null, true);
                if ($terminationDate === null) {
                    $summary['missing_date']++;
                    $summary['skipped']++;
                    $warnings[] = [
                        'line' => $line,
                        'warning' => 'Termination date missing or invalid. Row skipped.',
                        'value' => Arr::only($row, ['Store number', 'Name', 'SSN']),
                    ];
                    continue;
                }

                $employee = $this->findEmployee($store, $row, (bool) $opts['allow_name_only'], $employeeCache, $warnings, $line);
                if (!$employee) {
                    $summary['not_found']++;
                    $summary['skipped']++;
                    continue;
                }

                $latestStatus = EmployeeStatusHistory::query()
                    ->where('employee_id', $employee->id)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                if (!$latestStatus) {
                    $summary['skipped']++;
                    $warnings[] = [
                        'line' => $line,
                        'warning' => 'Employee has no status history. Row skipped.',
                        'value' => Arr::only($row, ['Store number', 'Name', 'SSN']),
                    ];
                    continue;
                }

                $latestStatusValue = $this->statusValue($latestStatus->status);
                if (!in_array($latestStatusValue, [EmployeeStatus::Resigned->value, EmployeeStatus::Terminated->value], true)) {
                    $summary['status_mismatch']++;
                    $summary['skipped']++;
                    $warnings[] = [
                        'line' => $line,
                        'warning' => 'Latest status is not terminated/resigned. Row skipped.',
                        'value' => $latestStatusValue,
                    ];
                    continue;
                }

                $currentEffectiveDate = $latestStatus->effective_date instanceof \DateTimeInterface
                    ? $latestStatus->effective_date->format('Y-m-d')
                    : (string) $latestStatus->effective_date;

                if ($currentEffectiveDate === $terminationDate) {
                    $summary['skipped']++;
                    continue;
                }

                if ($opts['dry_run']) {
                    $summary['updated']++;
                    continue;
                }

                $latestStatus->effective_date = $terminationDate;
                $latestStatus->save();

                $summary['updated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                $failures[] = [
                    'line' => $line,
                    'error' => $e->getMessage(),
                    'row' => Arr::only($row, ['Store number', 'Name', 'SSN']),
                ];
            }
        }

        return [
            'summary' => $summary,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    private function rows(string $path): \Generator
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headers = null;
        $line = 0;

        foreach ($file as $record) {
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

            if ($headers === null) {
                $headers = array_map(fn(string $header): string => $this->sanitizeHeader($header), $record);
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

    /**
     * @param array<string, string> $row
     */
    private function required(array $row, string $column, int $line): string
    {
        $value = $this->clean($row[$column] ?? null);
        if ($value === null) {
            throw new RuntimeException("Missing required value in column '{$column}' on CSV line {$line}.");
        }

        return $value;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function sanitizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        $trimmed = trim($header);
        if ($trimmed === '') {
            return $trimmed;
        }

        $key = strtolower(preg_replace('/[^a-z0-9]+/', '', $trimmed) ?? $trimmed);

        return self::HEADER_ALIASES[$key] ?? $trimmed;
    }

    private function resolveStore(string $storeNumber, array &$cache): Store
    {
        $key = trim($storeNumber);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $store = Store::query()->where('store_number', $key)->first();

        if (!$store || (int) ($store->id ?? 0) <= 0) {
            throw new RuntimeException("Store not found for store number '{$key}'.");
        }

        $cache[$key] = $store;

        return $store;
    }

    /**
     * @param array<string, string> $row
     * @param array<int, array<string, mixed>> $warnings
     */
    private function findEmployee(Store $store, array $row, bool $allowNameOnly, array &$cache, array &$warnings, int $line): ?Employee
    {
        $employees = $this->storeEmployees($store, $cache);

        $ssnDigits = preg_replace('/\D+/', '', (string) ($row['SSN'] ?? ''));
        if ($ssnDigits !== '') {
            $match = $employees->first(function (Employee $candidate) use ($ssnDigits): bool {
                return preg_replace('/\D+/', '', (string) $candidate->ssn) === $ssnDigits;
            });

            if ($match) {
                return $match;
            }
        }

        $name = $this->clean($row['Name'] ?? null);
        $birthDate = $this->normalizeDate($row['Birth Date'] ?? null, true);

        if ($name !== null && $birthDate !== null) {
            $parsed = $this->splitFullName($name);
            $csvNameFull = $this->normalizeName($parsed['first_name'], $parsed['middle_name'], $parsed['last_name'], $name);
            $csvNameFirstLast = $this->normalizeName($parsed['first_name'], null, $parsed['last_name'], $name);

            $match = $employees->first(function (Employee $candidate) use ($birthDate, $csvNameFull, $csvNameFirstLast): bool {
                $candidateFull = $this->normalizeName($candidate->first_name, $candidate->middle_name, $candidate->last_name, null);
                $candidateFirstLast = $this->normalizeName($candidate->first_name, null, $candidate->last_name, null);
                $birth = $candidate->obsession?->birth_date?->format('Y-m-d');

                $nameMatches = in_array($candidateFull, [$csvNameFull, $csvNameFirstLast], true)
                    || in_array($candidateFirstLast, [$csvNameFull, $csvNameFirstLast], true);

                return $nameMatches && $birth === $birthDate;
            });

            if ($match) {
                return $match;
            }
        }

        if ($allowNameOnly && $name !== null) {
            $parsed = $this->splitFullName($name);
            $csvNameFull = $this->normalizeName($parsed['first_name'], $parsed['middle_name'], $parsed['last_name'], $name);
            $csvNameFirstLast = $this->normalizeName($parsed['first_name'], null, $parsed['last_name'], $name);

            $match = $employees->first(function (Employee $candidate) use ($csvNameFull, $csvNameFirstLast): bool {
                $candidateFull = $this->normalizeName($candidate->first_name, $candidate->middle_name, $candidate->last_name, null);
                $candidateFirstLast = $this->normalizeName($candidate->first_name, null, $candidate->last_name, null);

                return in_array($candidateFull, [$csvNameFull, $csvNameFirstLast], true)
                    || in_array($candidateFirstLast, [$csvNameFull, $csvNameFirstLast], true);
            });

            if ($match) {
                return $match;
            }
        }

        $warnings[] = [
            'line' => $line,
            'warning' => 'Employee not found for store and provided identifiers. Row skipped.',
            'value' => Arr::only($row, ['Store number', 'Name', 'SSN', 'Birth Date']),
        ];

        return null;
    }

    private function storeEmployees(Store $store, array &$cache)
    {
        if (isset($cache[$store->id])) {
            return $cache[$store->id];
        }

        $cache[$store->id] = Employee::query()
            ->whereHas('stores', fn($q) => $q->where('store_id', $store->id))
            ->with('obsession')
            ->get();

        return $cache[$store->id];
    }

    private function statusValue(mixed $status): string
    {
        if ($status instanceof EmployeeStatus) {
            return $status->value;
        }

        return strtolower((string) $status);
    }

    private function normalizeDate(?string $value, bool $nullable = false): ?string
    {
        $clean = $this->clean($value);
        if ($clean === null) {
            return $nullable ? null : throw new RuntimeException('Missing required date value.');
        }

        $excelDate = $this->parseExcelSerialDate($clean);
        if ($excelDate !== null) {
            return $excelDate;
        }

        if (is_numeric($clean)) {
            return $nullable ? null : throw new RuntimeException("Unable to parse date '{$clean}'.");
        }

        try {
            $normalized = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
            $normalized = str_replace(['.', ','], '/', $normalized);

            $timestamp = strtotime($normalized);
            if ($timestamp !== false) {
                return Carbon::createFromTimestamp($timestamp)->toDateString();
            }

            return Carbon::parse($normalized)->toDateString();
        } catch (Throwable $e) {
            if ($nullable) {
                return null;
            }

            throw new RuntimeException("Unable to parse date '{$clean}'.", previous: $e);
        }
    }

    private function parseExcelSerialDate(string $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        $numeric = (float) $value;
        if ($numeric < 20000 || $numeric > 80000) {
            return null;
        }

        return Carbon::create(1899, 12, 30)->addDays((int) round($numeric))->toDateString();
    }

    /**
     * @return array{first_name: string|null, middle_name: string|null, last_name: string|null}
     */
    private function splitFullName(string $name): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($clean === '') {
            return [
                'first_name' => null,
                'middle_name' => null,
                'last_name' => null,
            ];
        }

        $parts = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) === 1) {
            return [
                'first_name' => $parts[0],
                'middle_name' => null,
                'last_name' => $parts[0],
            ];
        }

        $suffixes = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];
        $suffix = null;
        $last = array_pop($parts);
        if ($last !== null && in_array(strtolower($last), $suffixes, true)) {
            $suffix = $last;
            $last = array_pop($parts);
        }

        $first = array_shift($parts);
        $middle = $parts !== [] ? implode(' ', $parts) : null;
        $lastName = $last ?? '';

        if ($suffix !== null && $lastName !== '') {
            $lastName = trim($lastName . ' ' . $suffix);
        }

        return [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $lastName === '' ? null : $lastName,
        ];
    }

    private function normalizeName(?string $firstName, ?string $middleName, ?string $lastName, ?string $fallback): string
    {
        $parts = array_filter([
            $firstName,
            $middleName,
            $lastName,
        ], fn($value) => $value !== null && trim($value) !== '');

        $name = $parts !== [] ? implode(' ', $parts) : (string) $fallback;

        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }
}
