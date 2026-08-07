<?php

namespace App\Services\Humanity;

use RuntimeException;

/**
 * Thrown, never swallowed.
 *
 * The auth-server client in this codebase downgrades every failure to a quiet
 * false; this one must NOT, because the employee write-through depends on the
 * exception propagating out of the DB transaction to roll it back.
 */
class HumanityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $humanityStatus = null,
        public readonly ?int $httpStatus = null,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
