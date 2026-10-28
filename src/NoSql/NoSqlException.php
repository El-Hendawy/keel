<?php

declare(strict_types=1);

namespace Keel\NoSql;

/** A document database refused a request or could not be reached; the message names which. */
final class NoSqlException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $type = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
