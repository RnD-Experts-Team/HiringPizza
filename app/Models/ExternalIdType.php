<?php

namespace App\Models;

/**
 * The id_types labels that are cross-service contracts.
 *
 * OperationsPizza hardcodes these same strings (EmployeeExternalId::TCP /
 * ::HUMANITY) to lift external ids off replicated employee snapshots, so they
 * are constants here too — they were briefly env-configurable on this side
 * only, which meant one env edit could silently break replication.
 */
final class ExternalIdType
{
    public const TCP = 'TCP ID';

    public const HUMANITY = 'Humanity ID';

    /**
     * Sent to TCP as the employee's exportCode (see TcpEmployeeMapper::exportCode),
     * so TCP's payroll export carries the Paychex id.
     */
    public const PAYCHEX = 'Paychex ID';
}
