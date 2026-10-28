<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Container\Attribute\Scoped;

/**
 * Facts about the current request that middleware and services share:
 * the request id, the matched route pattern and the resolved client address.
 */
#[Scoped]
final class RequestContext
{
    public string $requestId = '';
    public ?string $route = null;
    public string $clientIp = '';
    public float $startedAt;

    /** When the caller stops waiting (microtime), from `Keel-Timeout-Ms`; null when unbounded. */
    public ?float $deadline = null;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }
}
