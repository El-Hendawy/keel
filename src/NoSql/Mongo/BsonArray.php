<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/** Forces a BSON array where a PHP array would be ambiguous: `new BsonArray([])`. */
final readonly class BsonArray
{
    /** @param list<mixed> $items */
    public function __construct(public array $items)
    {
    }
}
