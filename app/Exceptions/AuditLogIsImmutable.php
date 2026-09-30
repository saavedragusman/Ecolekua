<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown whenever code tries to update or delete an audit record (FND-024).
 */
class AuditLogIsImmutable extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("Audit records are append-only; {$operation} is not allowed.");
    }
}
