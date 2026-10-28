<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\SyncJobs;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class JobLog
{
    /** @var list<string> */
    public array $seen = [];
}
