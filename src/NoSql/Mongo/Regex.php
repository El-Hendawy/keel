<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/** A BSON regular expression, e.g. new Regex('^acme', 'i'). */
final readonly class Regex
{
    public function __construct(public string $pattern, public string $flags = '')
    {
    }
}
