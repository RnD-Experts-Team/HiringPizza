<?php

namespace App\Services\Tcp;

/**
 * The employee half of TCP Manager+. HiringPizza owns employee writes and
 * pushes them here; TCP's own connector propagates to Humanity.
 *
 * Nothing about shifts, punches or worked hours belongs on this interface —
 * that is OperationsPizza's concern.
 */
interface TcpEmployeeClientInterface
{
    public function getEmployee(string $employeeId): ?array;

    /** @return array<int, array<string, mixed>> */
    public function listEmployees(): array;

    /**
     * `employeeId` is CLIENT-SUPPLIED — TCP does not assign it. Setting it to
     * our own employee id is what makes this idempotent.
     */
    public function createEmployee(array $payload): array;

    public function updateEmployee(string $employeeId, array $payload): array;

    /** @return array<int, array<string, mixed>> */
    public function listJobCodes(): array;
}
