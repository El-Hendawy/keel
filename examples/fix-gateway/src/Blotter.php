<?php

declare(strict_types=1);

namespace FixGateway;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class Blotter
{
    /** @var list<ExecutionReport> */
    public array $reports = [];
}
