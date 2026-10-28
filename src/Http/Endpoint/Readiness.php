<?php

declare(strict_types=1);

namespace Keel\Http\Endpoint;

use Keel\Container\Attribute\All;
use Keel\Http\Responses;
use Keel\Observability\Health\HealthCheck;
use Keel\Observability\Health\WorkerState;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;

/** Readiness probe: runs every registered HealthCheck. Returns 503 while draining or when a check fails. */
#[Get('/health/ready', name: 'keel.health.ready')]
final readonly class Readiness
{
    /** @param list<HealthCheck> $checks */
    public function __construct(#[All(HealthCheck::class)] private array $checks)
    {
    }

    public function __invoke(WorkerState $worker): ResponseInterface
    {
        $results = [];
        $healthy = !$worker->draining;
        foreach ($this->checks as $check) {
            $start = hrtime(true);
            try {
                $check->check();
                $results[$check->name()] = ['status' => 'ok'];
            } catch (\Throwable $e) {
                $healthy = false;
                $results[$check->name()] = ['status' => 'failing', 'error' => $e->getMessage()];
            }
            $results[$check->name()]['durationMs'] = round((hrtime(true) - $start) / 1e6, 2);
        }

        return Responses::json([
            'status' => $healthy ? 'ok' : ($worker->draining ? 'draining' : 'failing'),
            'checks' => $results === [] ? new \stdClass() : $results,
        ], $healthy ? 200 : 503);
    }
}
