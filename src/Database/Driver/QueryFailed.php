<?php

declare(strict_types=1);

namespace Keel\Database\Driver;

/**
 * A failed statement on a non-PDO driver. Extends PDOException with the SQLSTATE in
 * errorInfo and the code, so code written for PDO errors keeps working.
 */
final class QueryFailed extends \PDOException
{
    public function __construct(string $message, string $sqlState, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, null, $message];
    }
}
