<?php

declare(strict_types=1);

namespace Keel\Observability\Health;

use Keel\Container\Attribute\Singleton;

/** Liveness state of this worker. A draining worker fails readiness so the load balancer stops sending traffic. */
#[Singleton]
final class WorkerState
{
    public bool $draining = false;
    public int $handled = 0;
    public readonly float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }
}
