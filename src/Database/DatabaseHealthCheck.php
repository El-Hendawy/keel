<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Container\Attribute\Singleton;
use Keel\Observability\Health\HealthCheck;

#[Singleton]
final readonly class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(private ConnectionManager $connections)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): void
    {
        $this->connections->writer()->query('SELECT 1');
    }
}
