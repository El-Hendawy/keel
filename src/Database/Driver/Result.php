<?php

declare(strict_types=1);

namespace Keel\Database\Driver;

/** Rows (column => value) and the number of rows a write affected. */
final readonly class Result
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public array $rows, public int $affected)
    {
    }
}
