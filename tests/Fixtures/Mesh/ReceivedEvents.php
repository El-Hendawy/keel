<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class ReceivedEvents
{
    /** @var list<array{type: string, tenant: ?string, data: array<mixed>}> */
    public array $events = [];
}
