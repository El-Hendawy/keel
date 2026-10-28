<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Container\Attribute\Scoped;
use Keel\Container\Disposable;

#[Scoped]
final class UnitOfWork implements Disposable
{
    /** @var list<string> */
    public static array $disposed = [];

    public function __construct(public Connection $connection)
    {
    }

    public function dispose(): void
    {
        self::$disposed[] = spl_object_hash($this);
    }
}
