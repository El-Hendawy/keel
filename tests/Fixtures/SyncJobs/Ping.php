<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\SyncJobs;

final readonly class Ping
{
    public function __construct(public string $name)
    {
    }
}
