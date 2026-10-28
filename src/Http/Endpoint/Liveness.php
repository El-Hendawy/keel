<?php

declare(strict_types=1);

namespace Keel\Http\Endpoint;

use Keel\Http\Responses;
use Keel\Observability\Health\WorkerState;
use Keel\Auth\Attribute\Anonymous;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;

/** Liveness probe: the worker is up and serving. */
#[Anonymous]
#[Get('/health/live', name: 'keel.health.live')]
final readonly class Liveness
{
    public function __invoke(WorkerState $worker): ResponseInterface
    {
        return Responses::json([
            'status' => 'ok',
            'uptimeSeconds' => round(microtime(true) - $worker->startedAt, 3),
            'requestsHandled' => $worker->handled,
        ]);
    }
}
