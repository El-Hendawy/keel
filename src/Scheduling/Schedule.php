<?php

declare(strict_types=1);

namespace Keel\Scheduling;

use Attribute;

/**
 * Runs an invokable class on a cron schedule (UTC), once per due minute across every
 * node: `keel schedule:work` (long-running) or `keel schedule:run` (from cron each minute).
 * The expression is validated at build time.
 *
 *     #[Schedule('0 3 * * *')]          // 03:00 every day
 *     #[Schedule('@hourly')]
 *     #[Schedule('*\/5 9-17 * * 1-5')]  // every 5 minutes in office hours
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Schedule
{
    public function __construct(public string $cron)
    {
    }
}
