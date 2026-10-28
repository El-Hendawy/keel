<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/** A MongoDB error: a failed command (with the server's code), a protocol or a connection error. */
final class MongoException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly string $codeName = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
