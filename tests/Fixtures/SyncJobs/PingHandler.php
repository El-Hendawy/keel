<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\SyncJobs;

use Keel\Queue\Handler;

#[Handler]
final readonly class PingHandler
{
    public function __construct(private JobLog $log)
    {
    }

    public function __invoke(Ping $job): void
    {
        $this->log->seen[] = $job->name;
    }
}
