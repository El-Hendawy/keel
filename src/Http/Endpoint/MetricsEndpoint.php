<?php

declare(strict_types=1);

namespace Keel\Http\Endpoint;

use Keel\Container\Attribute\Config;
use Keel\Http\Exception\NotFound;
use Keel\Http\Exception\Unauthorized;
use Keel\Http\Responses;
use Keel\Observability\Metrics\Metrics;
use Keel\Auth\Attribute\Anonymous;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Prometheus scrape endpoint. Protected by a bearer token when `metrics.token` is set. */
#[Anonymous]
#[Get('/metrics', name: 'keel.metrics')]
final readonly class MetricsEndpoint
{
    public function __construct(
        #[Config('metrics.enabled')] private bool $enabled = true,
        #[Config('metrics.token')] private string $token = '',
    ) {
    }

    public function __invoke(Metrics $metrics, ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->enabled) {
            throw new NotFound();
        }
        if ($this->token !== '' && !hash_equals('Bearer ' . $this->token, $request->getHeaderLine('Authorization'))) {
            throw new Unauthorized('A valid metrics token is required.');
        }

        return Responses::text($metrics->render(), 200, 'text/plain; version=0.0.4');
    }
}
