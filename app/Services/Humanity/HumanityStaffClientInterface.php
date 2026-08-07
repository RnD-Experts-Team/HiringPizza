<?php

namespace App\Services\Humanity;

/**
 * The employee half of Humanity's API. HiringPizza owns staff writes; it never
 * touches shifts (that is OperationsPizza's job).
 */
interface HumanityStaffClientInterface
{
    /**
     * Look up by the external id we control (Humanity's `eid`).
     * This is what makes the upsert idempotent.
     */
    public function findByEid(string $eid): ?array;

    public function findByEmail(string $email): ?array;

    /** @return array<int, array<string, mixed>> */
    public function listEmployees(bool $includeInactive = true): array;

    /** @return array<string, mixed> the created record, including its id */
    public function createEmployee(array $payload): array;

    /** @return array<string, mixed> */
    public function updateEmployee(string $humanityEmployeeId, array $payload): array;
}
