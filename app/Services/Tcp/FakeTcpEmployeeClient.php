<?php

namespace App\Services\Tcp;

/**
 * In-memory TCP double. Default driver, so the employee push can be built and
 * verified before credentials exist.
 *
 * Models TCP's id semantics: `employeeId` is TCP-ASSIGNED when absent (the
 * flow we use — the live roster has native ids our auto-increment would
 * collide with), and must be unique when a caller does supply one.
 */
class FakeTcpEmployeeClient implements TcpEmployeeClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $employees = [];

    /** @var array<int, array<string, mixed>> */
    public array $jobCodes = [];

    /** @var array<int, array{op:string, args:array}> */
    public array $calls = [];

    /** Mimics TCP's native id space, far from our auto-increment range. */
    private int $nextEmployeeId = 9000001;

    private array $failures = [];

    public function failNext(string $operation, ?\Throwable $exception = null): void
    {
        $this->failures[$operation] = $exception ?? new TcpException("Simulated TCP failure on {$operation}");
    }

    public function seed(string $employeeId, array $attributes = []): void
    {
        $this->employees[$employeeId] = array_merge([
            'employeeId' => $employeeId,
            'firstName' => 'Test',
            'lastName' => "Employee {$employeeId}",
        ], $attributes);
    }

    /**
     * Real per-store codes look like description "Crew Member - 3795-01" plus
     * a "Restaurant Id" custom field carrying the store_number.
     */
    public function seedJobCode(string $id, string $description = 'Default', ?string $storeNumber = null, bool $clockable = true): void
    {
        $customFields = $storeNumber === null
            ? []
            : [['description' => 'Restaurant Id', 'value' => $storeNumber]];

        $this->jobCodes[] = [
            'jobCodeId' => (int) $id,
            'description' => $description,
            'clockable' => $clockable,
            'customFields' => $customFields,
        ];
    }

    public function getEmployee(string $employeeId): ?array
    {
        $this->guard('getEmployee');

        return $this->employees[$employeeId] ?? null;
    }

    public function listEmployees(): array
    {
        $this->guard('listEmployees');

        return array_values($this->employees);
    }

    public function createEmployee(array $payload): array
    {
        $this->guard('createEmployee');
        $this->assertWritesEnabled('create employee');

        $employeeId = (string) ($payload['employeeId'] ?? '');

        if ($employeeId === '') {
            // TCP's documented behaviour: a null employeeId auto-generates
            // the next available id.
            $employeeId = (string) $this->nextEmployeeId++;
        } elseif (isset($this->employees[$employeeId])) {
            throw new TcpException("TCP create employee failed: employeeId {$employeeId} already exists.");
        }

        $this->calls[] = ['op' => 'createEmployee', 'args' => ['employeeId' => $employeeId]];

        return $this->employees[$employeeId] = ['employeeId' => $employeeId] + $payload;
    }

    public function updateEmployee(string $employeeId, array $payload): array
    {
        $this->guard('updateEmployee');
        $this->assertWritesEnabled('update employee');

        if (!isset($this->employees[$employeeId])) {
            throw new TcpException("TCP update employee failed: {$employeeId} not found.");
        }

        $this->calls[] = ['op' => 'updateEmployee', 'args' => ['employeeId' => $employeeId]];

        return $this->employees[$employeeId] = array_merge($this->employees[$employeeId], $payload);
    }

    public function listJobCodes(): array
    {
        $this->guard('listJobCodes');

        return $this->jobCodes;
    }

    private function guard(string $operation): void
    {
        if (isset($this->failures[$operation])) {
            $exception = $this->failures[$operation];
            unset($this->failures[$operation]);

            throw $exception;
        }
    }

    /** Same gate as the real client, so the safety flag is exercised by tests. */
    private function assertWritesEnabled(string $operation): void
    {
        if (!config('tcp.writes_enabled')) {
            throw new TcpException(
                "TCP writes are disabled (TCP_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }
    }
}
