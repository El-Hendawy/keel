<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/** The internal BSON timestamp used by replication (not a date: use \DateTimeImmutable for those). */
final readonly class Timestamp
{
    public function __construct(public int $seconds, public int $increment)
    {
    }
}
