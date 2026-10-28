<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Factory;
use Keel\Container\Attribute\Singleton;
use Keel\Container\Lifetime;

#[Singleton]
final readonly class ConnectionFactory
{
    public function __construct(#[Config('db.dsn')] private string $dsn)
    {
    }

    #[Factory(Lifetime::Singleton)]
    public function connection(): Connection
    {
        return new Connection($this->dsn);
    }
}
