<?php

namespace App\Services\Tcp;

/**
 * In-memory TCP double. Default driver, so the employee push can be built and
 * verified before credentials exist.
 *
 * Enforces the one rule that matters: `employeeId` is client-supplied and must
 * be unique, so a duplicate create fails here exactly as it would in TCP.
 */
class FakeTcpEmployeeClient implements TcpEmployeeClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $employees = [];

    /** @var array<int, array<string, mixed>> */
    public array $jobCodes = [];

    /** @var array<int, array{op:string, args:array}> */
    public array $calls = [];

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

    public function seedJobCode(string $id, string $name = 'Default'): void
    {
        $this->jobCodes[] = ['jobCodeId' => $id, 'name' => $name];
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
            throw new TcpException('TCP create employee failed: employeeId is required and client-supplied.');
        }

        if (isset($this->employees[$employeeId])) {
            throw new TcpException("TCP create employee failed: employeeId {$employeeId} already exists.");
        }

        $this->calls[] = ['op' => 'createEmployee', 'args' => ['employeeId' => $employeeId]];

        return $this->employees[$employeeId] = $payload;
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
