<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

final readonly class Received
{
    public function __construct(public bool $handled, public int $total)
    {
    }
}
