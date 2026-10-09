<?php

namespace App\Services\Tcp;

use RuntimeException;

/**
 * Thrown, never swallowed — the employee write-through depends on this
 * propagating out of the DB transaction to roll it back.
 */
class TcpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly array $errors = [],
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
