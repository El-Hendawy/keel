<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

/** A class we "do not own": built by a factory. */
final class Connection
{
    public bool $closed = false;

    public function __construct(public readonly string $dsn)
    {
    }
}
