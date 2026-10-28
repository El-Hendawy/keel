<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Database\Database;
use Keel\Routing\Attribute\Get;

/** A query that takes $ms on the database server (PostgreSQL), like a real query with latency. */
#[Get('/db/{ms}')]
final class Db
{
    public function __invoke(int $ms, Database $db): Pong
    {
        $db->select('SELECT pg_sleep(?) AS slept', [$ms / 1000]);

        return new Pong('queried', getmypid() ?: 0);
    }
}
