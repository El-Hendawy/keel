<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\ServiceUnavailable;
use Keel\Observability\Health\WorkerState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sheds load before the node falls over.
 *
 * When the proxy reports how long a request waited in its queue (X-Request-Start, as
 * set by nginx, Caddy, Heroku-style routers), requests that already waited longer
 * than `load_shedding.max_queue_ms` are answered with 503 immediately: the client has
 * probably given up, and working on them only makes the queue longer.
 */
#[Singleton]
final readonly class LoadSheddingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private WorkerState $worker,
        #[Config('load_shedding.max_queue_ms')] private int $maxQueueMs = 2000,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->worker->handled++;
        $start = self::queueStart($request->getHeaderLine('X-Request-Start'));
        if ($this->maxQueueMs > 0 && $start !== null && (microtime(true) - $start) * 1000 > $this->maxQueueMs) {
            throw new ServiceUnavailable('The server is overloaded. Retry shortly.', 1);
        }

        return $handler->handle($request);
    }

    /** Accepts `t=<seconds|ms|µs>` or a bare number, returning seconds since the epoch. */
    public static function queueStart(string $header): ?float
    {
        if (preg_match('/(?:t=)?(\d+(?:\.\d+)?)/', $header, $m) !== 1) {
            return null;
        }
        $value = (float) $m[1];

        return match (true) {
            $value > 1e15 => $value / 1e6,
            $value > 1e12 => $value / 1e3,
            default => $value,
        };
    }
}
