<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/** Forces a BSON int64 for a small value, where the server expects a long (cursor ids). */
final readonly class Int64
{
    public function __construct(public int $value)
    {
    }
}
