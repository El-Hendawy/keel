<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/** BSON binary data with its subtype (0 generic, 4 UUID). */
final readonly class Binary
{
    public function __construct(public string $data, public int $subtype = 0)
    {
    }
}
