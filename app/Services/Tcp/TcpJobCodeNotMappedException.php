<?php

namespace App\Services\Tcp;

/**
 * No TCP job code could be resolved locally for a new hire's store/position.
 *
 * Thrown instead of silently omitting `defaultJobCode` from the create
 * payload — which used to reach TCP and come back as an opaque "The cell
 * must have a value". A TcpException subclass so every existing catch site
 * (the employee-create transaction rollback, tests) handles it unchanged.
 */
class TcpJobCodeNotMappedException extends TcpException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
