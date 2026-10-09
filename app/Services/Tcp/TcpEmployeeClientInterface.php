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
     * The payload carries NO `employeeId` — TCP assigns the next available id
     * (the live roster's native ids would collide with our auto-increment).
     * `exportCode` = our employee id is the recoverable link; NEVER auto-retry
     * this call, because a timed-out create may have landed.
     */
    public function createEmployee(array $payload): array;

    public function updateEmployee(string $employeeId, array $payload): array;

    /** @return array<int, array<string, mixed>> */
    public function listJobCodes(): array;
}
