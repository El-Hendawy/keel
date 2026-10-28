<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Misconfigured;

use Keel\Scheduling\Schedule;

#[Schedule('61 * * * *')]
final class BadlyScheduled
{
    public function __invoke(): void
    {
    }
}
