<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\EmployeeObsession;
use App\Models\IdType;
use App\Models\Position;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;
use Throwable;

class EmployeeCsvImportService
{
    private const DAYS = [
        'sunday',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
    ];

    /**
     * @return array{summary: array<string, int>, failures: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function import(string $path, array $options = []): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("CSV file does not exist: {$path}");
        }

        $opts = array_merge([
            'dry_run' => false,
            'mode' => 'update', // update|create-only|skip-existing
            'create_missing_stores' => true,
            'create_missing_positions' => true,
            'create_missing_id_types' => true,
            'altametrics_id_type' => 'Altametrics ID',
            'paychecks_id_type' => 'Paychecks ID',
            'clock_code_id_type' => 'Altametrics Clock Code',
        ], $options);

        $positionCache = [];
        $storeCache = [];
        $idTypeCache = [];
        $fallbackSsn = 100000;
        $warnings = [];
        $failures = [];
        $summary = [
            'rows_total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($this->rows($path) as $line => $row) {
            $summary['rows_total']++;

            try {
                $payload = $this->buildPayload($row, $line, $opts, $storeCache, $positionCache, $idTypeCache, $warnings, $fallbackSsn);
                $store = $payload['store'];
                $employeeData = $payload['employee'];

                $existing = $this->findExistingEmployee($store, $payload['match']);

                if ($existing && $opts['mode'] === 'create-only') {
                    $summary['skipped']++;
                    continue;
                }

                if ($existing && $opts['mode'] === 'skip-existing') {
                    $summary['skipped']++;
                    continue;
                }

                if ($opts['dry_run']) {
                    if ($existing) {
                        $summary['updated']++;
                    } else {
                        $summary['created']++;
                    }

                    continue;
                }

                DB::transaction(function () use ($store, $employeeData, $existing, &$summary): void {
                    $service = app(EmployeeWorkflowService::class);

                    if ($existing) {
                        $service->update($store, $existing, $employeeData);
                        $summary['updated']++;

                        return;
                    }

                    $service->create($store, $employeeData);
                    $summary['created']++;
                });
            } catch (Throwable $e) {
                $summary['failed']++;
                $failures[] = [
                    'line' => $line,
                    'error' => $e->getMessage(),
                    'row' => Arr::only($row, ['Store number', 'First_name', 'Last_name', 'SSN_Number', 'Altametrics ID']),
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
     * @param array<string, mixed> $opts
     * @param array<string, Store> $storeCache
     * @param array<string, Position> $positionCache
     * @param array<string, IdType> $idTypeCache
     * @param array<int, array<string, mixed>> $warnings
     * @return array{store: Store, employee: array<string, mixed>, match: array<string, string|null>}
     */
    private function buildPayload(
        array $row,
        int $line,
        array $opts,
        array &$storeCache,
        array &$positionCache,
        array &$idTypeCache,
        array &$warnings,
        int &$fallbackSsn
    ): array {
        $storeNumber = $this->required($row, 'Store number', $line);
        $persistCreates = !(bool) $opts['dry_run'];
        $store = $this->resolveStore($storeNumber, (bool) $opts['create_missing_stores'], $persistCreates, $storeCache);

        $status = $this->normalizeStatus($this->required($row, 'Status', $line));
        $hiredDate = $this->normalizeDate($row['Hired Date'] ?? null, true);
        $resignedDate = $this->normalizeDate($row['Resigned Date'] ?? null, true);
        $terminatedDate = $this->normalizeDate($row['Terminated date'] ?? null, true);
        $effectiveStatusDate = $this->resolveEffectiveStatusDate($status, $hiredDate, $resignedDate, $terminatedDate);

        $position = $this->resolvePosition($row['Position'] ?? '', (bool) $opts['create_missing_positions'], $persistCreates, $positionCache);

        $availability = $this->normalizeAvailability($row['Availability_Days'] ?? '', $row['Shift'] ?? '');

        $idRows = [];

        $altametricsId = $this->clean($row['Altametrics ID'] ?? null);
        if ($altametricsId !== null) {
            $idRows[] = [
                'id_type_id' => $this->resolveIdTypeId((string) $opts['altametrics_id_type'], (bool) $opts['create_missing_id_types'], $persistCreates, $idTypeCache),
                'id_value' => $altametricsId,
            ];
        }

        $paychecksId = $this->clean($row['Paychecks_ID'] ?? null);
        if ($paychecksId !== null) {
            $idRows[] = [
                'id_type_id' => $this->resolveIdTypeId((string) $opts['paychecks_id_type'], (bool) $opts['create_missing_id_types'], $persistCreates, $idTypeCache),
                'id_value' => $paychecksId,
            ];
        }

        $clockCode = $this->clean($row['Altametrics Clock code'] ?? null);
        if ($clockCode !== null) {
            $idRows[] = [
                'id_type_id' => $this->resolveIdTypeId((string) $opts['clock_code_id_type'], (bool) $opts['create_missing_id_types'], $persistCreates, $idTypeCache),
                'id_value' => $clockCode,
            ];
        }

        $financial = $this->buildFinancialInfo($row, $effectiveStatusDate, $line, $warnings);

        $data = [
            'first_name' => $this->required($row, 'First_name', $line),
            'middle_name' => $this->clean($row['Middle_name'] ?? null),
            'last_name' => $this->required($row, 'Last_name', $line),
            'gender' => $this->normalizeGender($this->required($row, 'Gender', $line)),
            'ssn' => $this->normalizeSsn($row['SSN_Number'] ?? null, $line, $warnings, $fallbackSsn),
            'employment_type' => $this->normalizeEmploymentType($this->required($row, 'Employment type', $line)),
            'status_history' => [
                [
                    'status' => $status,
                    'effective_date' => $effectiveStatusDate,
                    'store_id' => $store->id,
                ]
            ],
            'store_assignments' => [
                [
                    'store_id' => $store->id,
                    'effective_date' => $hiredDate ?? $effectiveStatusDate,
                ]
            ],
            'contacts' => $this->buildContacts($row),
            'addresses' => [$this->buildAddress($row)],
            'availability' => $availability,
            'pay_history' => $this->buildPayHistory($row, $effectiveStatusDate),
            'obsession' => [
                't_shirt' => $this->normalizeShirtSize($row['Size'] ?? ''),
                'birth_date' => $this->normalizeDate($this->required($row, 'Birth_date', $line)),
            ],
        ];

        if ($position) {
            $data['positions'] = [
                [
                    'position_id' => $position->id,
                    'effective_date' => $hiredDate ?? $effectiveStatusDate,
                ]
            ];
        }

        if ($idRows !== []) {
            $data['employee_ids'] = $idRows;
        }

        if ($financial !== null) {
            $data['financial_info'] = [$financial];
        }

        return [
            'store' => $store,
            'employee' => $data,
            'match' => [
                'altametrics_id' => $altametricsId,
                'paychecks_id' => $paychecksId,
                'ssn' => $data['ssn'],
                'full_name' => strtolower(trim($data['first_name'] . ' ' . $data['last_name'])),
                'birth_date' => $data['obsession']['birth_date'],
            ],
        ];
    }

    /**
     * @param array<string, string|null> $match
     */
    private function findExistingEmployee(Store $store, array $match): ?Employee
    {
        foreach (['altametrics_id', 'paychecks_id'] as $idKey) {
            if (!empty($match[$idKey])) {
                $employee = Employee::query()
                    ->whereHas('stores', fn($q) => $q->where('store_id', $store->id))
                    ->whereHas('ids', fn($q) => $q->where('id_value', $match[$idKey]))
                    ->first();

                if ($employee) {
                    return $employee;
                }
            }
        }

        $ssn = preg_replace('/\D+/', '', (string) ($match['ssn'] ?? ''));
        if ($ssn !== '') {
            $employee = Employee::query()
                ->whereHas('stores', fn($q) => $q->where('store_id', $store->id))
                ->get()
                ->first(function (Employee $candidate) use ($ssn): bool {
                    return preg_replace('/\D+/', '', (string) $candidate->ssn) === $ssn;
                });

            if ($employee) {
                return $employee;
            }
        }

        $fullName = (string) ($match['full_name'] ?? '');
        $birthDate = (string) ($match['birth_date'] ?? '');

        if ($fullName !== '' && $birthDate !== '') {
            return Employee::query()
                ->whereHas('stores', fn($q) => $q->where('store_id', $store->id))
                ->whereHas('obsession', fn($q) => $q->whereDate('birth_date', $birthDate))
                ->whereRaw("LOWER(CONCAT(first_name, ' ', last_name)) = ?", [$fullName])
                ->first();
        }

        return null;
    }

    /**
     * @param array<string, string> $row
     * @return array<int, array<string, mixed>>
     */
    private function buildContacts(array $row): array
    {
        $contacts = [];

        $email = $this->clean($row['Email'] ?? null);
        if ($email !== null) {
            $contacts[] = [
                'contact_name' => 'Primary Email',
                'contact_type' => 'email',
                'contact_value' => strtolower($email),
                'is_primary' => true,
            ];
        }

        $phoneDigits = preg_replace('/\D+/', '', (string) ($row['Phone'] ?? ''));
        if ($phoneDigits !== '') {
            $contacts[] = [
                'contact_name' => 'Primary Phone',
                'contact_type' => 'phone',
                'contact_value' => $phoneDigits,
                'is_primary' => true,
            ];
        }

        return $contacts;
    }

    /**
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    private function buildAddress(array $row): array
    {
        $country = strtoupper((string) ($row['Country'] ?? ''));
        if ($country === '' || $country === 'UNITED STATES') {
            $country = 'US';
        }

        return [
            'address_name' => 'Primary',
            'address_1' => $this->fallback($row['Address_1'] ?? '', 'Unknown'),
            'address_2' => $this->clean($row['Address_2'] ?? null),
            'city' => $this->fallback($row['City'] ?? '', 'Unknown'),
            'state' => strtoupper($this->fallback($row['State'] ?? '', 'NA')),
            'zip_code' => $this->fallback($row['Zip Code'] ?? '', '00000'),
            'country' => $country,
            'is_primary' => true,
        ];
    }

    /**
     * @param array<string, string> $row
     * @return array<int, array<string, mixed>>
     */
    private function buildPayHistory(array $row, string $effectiveDate): array
    {
        $base = $this->normalizeMoney($row['Base_pay'] ?? null);
        $performance = $this->normalizeMoney($row['Performance_pay'] ?? null);

        if ($base === null && $performance === null) {
            return [];
        }

        return [
            [
                'base_pay' => $base ?? 0,
                'performance_pay' => $performance ?? 0,
                'effective_date' => $effectiveDate,
            ]
        ];
    }

    /**
     * @param array<string, string> $row
     * @param array<int, array<string, mixed>> $warnings
     * @return array<string, mixed>|null
     */
    private function buildFinancialInfo(array $row, string $effectiveDate, int $line, array &$warnings): ?array
    {
        $accountNumber = $this->clean($row['Account_Number'] ?? null);
        $routingNumber = $this->clean($row['Routing_Number'] ?? null);
        $accountTypeRaw = $this->clean($row['Account_type'] ?? null);

        if ($accountNumber === null && $routingNumber === null && $accountTypeRaw === null) {
            return null;
        }

        if ($accountNumber === null || $routingNumber === null) {
            $warnings[] = [
                'line' => $line,
                'warning' => 'Incomplete financial data. Financial info was skipped.',
            ];

            return null;
        }

        $accountType = $this->normalizeAccountType($accountTypeRaw ?? '');

        if ($accountType === null) {
            $warnings[] = [
                'line' => $line,
                'warning' => 'Unknown account type. Defaulted to checking.',
                'value' => $accountTypeRaw,
            ];
            $accountType = 'checking';
        }

        return [
            'account_number' => $accountNumber,
            'routing_number' => $routingNumber,
            'account_type' => $accountType,
            'effective_date' => $effectiveDate,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAvailability(string $availabilityDaysRaw, string $shiftRaw): array
    {
        $availabilityDays = strtolower(trim(preg_replace('/\s+/', ' ', $availabilityDaysRaw) ?? $availabilityDaysRaw));
        $shift = $this->normalizeShift($shiftRaw) ?? 'OP';

        if ($availabilityDays === '') {
            if (trim($shiftRaw) === '') {
                return [];
            }

            return $this->availabilityForDays(self::DAYS, $shift);
        }

        if (
            str_contains($availabilityDays, 'open availability') ||
            $availabilityDays === 'op' ||
            str_contains($availabilityDays, 'high demand')
        ) {
            return $this->availabilityForDays(self::DAYS, $shift);
        }

        if (preg_match('/^(weekdays(\s*&\s*sunday|\s+and\s+sunday)?|weekdays\s*&\s*sunday)$/', $availabilityDays) === 1) {
            return $this->availabilityForDays(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $shift);
        }

        if (str_contains($availabilityDays, 'weekends only')) {
            return $this->availabilityForDays(['saturday', 'sunday'], $shift);
        }

        if (str_contains($availabilityDays, 'only working a sundays') || str_contains($availabilityDays, 'sunday only') || $availabilityDays === 'sun') {
            return $this->availabilityForDays(['sunday'], $shift);
        }

        if (str_contains($availabilityDays, 'cannot work weekends')) {
            return $this->availabilityForDays(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $shift);
        }

        if (preg_match('/\bone day off per week\b|\btwo days off\b|\bthree days off\b|\bfour days off\b|\b5 days off\b|\b6 days off\b/', $availabilityDays) === 1) {
            return [];
        }

        if (in_array($availabilityDays, ['sat', 'saturday only'], true)) {
            return $this->availabilityForDays(['saturday'], $shift);
        }

        if (str_contains($availabilityDays, 'off')) {
            $offDays = $this->extractDays($availabilityDays);
            if ($offDays !== []) {
                $workDays = array_values(array_diff(self::DAYS, $offDays));

                return $this->availabilityForDays($workDays, $shift);
            }
        }

        if (preg_match('/\bop\s+pm\b|\bpm\s+op\b|\bam\s+pm\b|\bpm\s+am\b/', $availabilityDays) === 1) {
            return $this->availabilityForDays(self::DAYS, $shift);
        }

        return [];
    }

    /**
     * @param array<int, string> $days
     * @return array<int, array<string, mixed>>
     */
    private function availabilityForDays(array $days, string $shift): array
    {
        return array_map(static fn(string $day): array => [
            'day_of_week' => $day,
            'shift_type' => $shift,
        ], $days);
    }

    /**
     * @return array<int, string>
     */
    private function extractDays(string $text): array
    {
        $found = [];

        foreach (self::DAYS as $day) {
            if (str_contains($text, $day)) {
                $found[] = $day;
            }
        }

        return $found;
    }

    private function resolveStore(string $storeNumber, bool $allowCreate, bool $persistCreate, array &$cache): Store
    {
        $key = trim($storeNumber);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $store = Store::query()->where('store_number', $key)->first();

        if (!$store && $allowCreate) {
            if ($persistCreate) {
                $nextId = (int) (Store::query()->max('id') ?? 0) + 1;
                $store = Store::query()->create([
                    'id' => $nextId,
                    'store_number' => $key,
                ]);
            } else {
                $store = new Store([
                    'id' => (int) abs(crc32($key)),
                    'store_number' => $key,
                ]);
            }
        }

        if (!$store) {
            throw new RuntimeException("Store not found for store number '{$key}'.");
        }

        $cache[$key] = $store;

        return $store;
    }

    private function resolvePosition(string $positionRaw, bool $allowCreate, bool $persistCreate, array &$cache): ?Position
    {
        $label = trim($positionRaw);

        if ($label === '') {
            return null;
        }

        $key = strtoupper($label);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $position = Position::query()
            ->whereRaw('UPPER(label) = ?', [$key])
            ->first();

        if (!$position && $allowCreate) {
            if ($persistCreate) {
                $position = Position::query()->create([
                    'label' => $label,
                ]);
            } else {
                $position = new Position([
                    'id' => (int) abs(crc32('position:' . $label)),
                    'label' => $label,
                ]);
            }
        }

        if (!$position) {
            throw new RuntimeException("Position not found: '{$label}'.");
        }

        $cache[$key] = $position;

        return $position;
    }

    private function resolveIdTypeId(string $label, bool $allowCreate, bool $persistCreate, array &$cache): int
    {
        $key = strtoupper(trim($label));

        if (isset($cache[$key])) {
            return $cache[$key]->id;
        }

        $idType = IdType::query()->whereRaw('UPPER(label) = ?', [$key])->first();

        if (!$idType && $allowCreate) {
            if ($persistCreate) {
                $idType = IdType::query()->create([
                    'label' => trim($label),
                ]);
            } else {
                $idType = new IdType([
                    'id' => (int) abs(crc32('id_type:' . $label)),
                    'label' => trim($label),
                ]);
            }
        }

        if (!$idType) {
            throw new RuntimeException("ID type not found: '{$label}'.");
        }

        $cache[$key] = $idType;

        return $idType->id;
    }

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

    private function fallback(string $value, string $default): string
    {
        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    private function normalizeStatus(string $value): string
    {
        $v = strtolower(trim($value));

        return match ($v) {
            'hired' => 'hired',
            'resigned' => 'resigned',
            'terminated' => 'terminated',
            'rehired' => 'rehired',
            'oje' => 'OJE',
            default => throw new RuntimeException("Unsupported status value: '{$value}'."),
        };
    }

    private function normalizeGender(string $value): string
    {
        $v = strtolower(trim($value));

        return match ($v) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => throw new RuntimeException("Unsupported gender value: '{$value}'."),
        };
    }

    private function normalizeEmploymentType(string $value): string
    {
        $v = strtoupper(trim($value));

        return match ($v) {
            'W2' => 'W2',
            '1099' => '1099',
            default => throw new RuntimeException("Unsupported employment type value: '{$value}'."),
        };
    }

    private function normalizeShift(string $value): ?string
    {
        $v = strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        if ($v === '') {
            return null;
        }

        if (preg_match('/\b(AM|PM|OP|CL)\b/', $v, $matches) === 1) {
            return $matches[1] === 'CL' ? 'OP' : $matches[1];
        }

        return match ($v) {
            'AM', 'PM', 'OP' => $v,
            'CL' => 'OP',
            default => null,
        };
    }

    private function normalizeShirtSize(string $value): ?string
    {
        $v = strtoupper(trim($value));

        return match ($v) {
            'L', 'M', 'S', 'XL', 'XS', '2XL', '3XL', '4XL', '5XL', '6XL' => $v,
            default => null,
        };
    }

    private function normalizeAccountType(string $value): ?string
    {
        $v = strtolower(trim($value));

        return match ($v) {
            'checking' => 'checking',
            'savings' => 'savings',
            default => null,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     */
    private function normalizeSsn(?string $value, int $line, array &$warnings, int &$fallbackSsn): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits !== null && $digits !== '') {
            return $digits;
        }

        $fallbackSsn++;
        $fallback = (string) $fallbackSsn;
        $warnings[] = [
            'line' => $line,
            'warning' => 'Missing or invalid SSN. Fallback SSN generated.',
            'value' => $fallback,
        ];

        return $fallback;
    }

    private function normalizeDate(?string $value, bool $nullable = false): ?string
    {
        $clean = $this->clean($value);
        if ($clean === null) {
            return $nullable ? null : throw new RuntimeException('Missing required date value.');
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

    private function resolveEffectiveStatusDate(string $status, ?string $hiredDate, ?string $resignedDate, ?string $terminatedDate): string
    {
        return match ($status) {
            'resigned' => $resignedDate ?? $hiredDate ?? now()->toDateString(),
            'terminated' => $terminatedDate ?? $hiredDate ?? now()->toDateString(),
            default => $hiredDate ?? now()->toDateString(),
        };
    }

    private function normalizeMoney(?string $value): ?float
    {
        $clean = $this->clean($value);
        if ($clean === null) {
            return null;
        }

        $numeric = preg_replace('/[^\d.\-]/', '', $clean);
        if ($numeric === '' || !is_numeric($numeric)) {
            return null;
        }

        return round((float) $numeric, 2);
    }

    private function sanitizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return trim($header);
    }
}
