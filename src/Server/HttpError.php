<?php

declare(strict_types=1);

namespace Keel\Server;

/** @internal a request the server rejects before it reaches the application */
final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }
}
