<?php

namespace App\Services\Humanity;

/**
 * In-memory double. Default driver, so the employee push can be built and
 * tested before anyone registers an app in Humanity's UI.
 *
 * Mimics the behaviours that actually break integrations: string ids, eid
 * lookups, and arm-able failures so the fail-the-request rollback is provable.
 */
class FakeHumanityStaffClient implements HumanityStaffClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $employees = [];

    /** @var array<int, array{op:string, args:array}> */
    public array $calls = [];

    private int $nextId = 90000;

    private array $failures = [];

    public function failNext(string $operation, ?\Throwable $exception = null): void
    {
        $this->failures[$operation] = $exception ?? new HumanityException("Simulated Humanity failure on {$operation}");
    }

    public function seed(string $id, array $attributes = []): void
    {
        $this->employees[$id] = array_merge([
            'id' => $id,
            'eid' => null,
            'name' => "Employee {$id}",
            'email' => null,
            'status' => 1,
        ], $attributes);
    }

    public function findByEid(string $eid): ?array
    {
        $this->guard('findByEid');

        foreach ($this->employees as $employee) {
            if (($employee['eid'] ?? null) !== null && (string) $employee['eid'] === $eid) {
                return $employee;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?array
    {
        $this->guard('findByEmail');

        foreach ($this->employees as $employee) {
            $candidate = strtolower(trim((string) ($employee['email'] ?? '')));

            if ($candidate !== '' && $candidate === strtolower(trim($email))) {
                return $employee;
            }
        }

        return null;
    }

    public function listEmployees(bool $includeInactive = true): array
    {
        $this->guard('listEmployees');

        return array_values($this->employees);
    }

    public function createEmployee(array $payload): array
    {
        $this->guard('createEmployee');
        $this->assertWritesEnabled('create employee');

        $id = (string) $this->nextId++;
        $this->employees[$id] = array_merge($payload, ['id' => $id]);
        $this->calls[] = ['op' => 'createEmployee', 'args' => ['id' => $id, 'eid' => $payload['eid'] ?? null]];

        return $this->employees[$id];
    }

    public function updateEmployee(string $humanityEmployeeId, array $payload): array
    {
        $this->guard('updateEmployee');
        $this->assertWritesEnabled('update employee');

        if (!isset($this->employees[$humanityEmployeeId])) {
            throw new HumanityException("Humanity update employee failed: {$humanityEmployeeId} not found");
        }

        $this->employees[$humanityEmployeeId] = array_merge($this->employees[$humanityEmployeeId], $payload);
        $this->calls[] = ['op' => 'updateEmployee', 'args' => ['id' => $humanityEmployeeId]];

        return $this->employees[$humanityEmployeeId];
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
        if (!config('humanity.writes_enabled')) {
            throw new HumanityException(
                "Humanity writes are disabled (HUMANITY_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }
    }
}
