<?php

declare(strict_types=1);

namespace Keel\Observability\Health;

/**
 * A readiness check. Every service implementing this interface is picked up by
 * GET /health/ready automatically. Keep checks fast: they run on every probe.
 */
interface HealthCheck
{
    public function name(): string;

    /** Return normally when healthy; throw with a useful message when not. */
    public function check(): void;
}
