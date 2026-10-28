<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Http\Exception\TooManyRequests;
use Keel\Http\RateLimit\RateLimitStore;
use Keel\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Per-client token bucket, on by default. Emits the IETF RateLimit headers.
 * Health and metrics endpoints are exempt so probes never get throttled.
 */
#[Scoped]
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    /** @param list<string> $exempt path prefixes */
    public function __construct(
        private RateLimitStore $store,
        private RequestContext $context,
        #[Config('rate_limit.enabled')] private bool $enabled = true,
        #[Config('rate_limit.capacity')] private int $capacity = 120,
        #[Config('rate_limit.per_seconds')] private int $perSeconds = 60,
        #[Config('rate_limit.exempt')] private array $exempt = ['/health/', '/metrics'],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled || $this->isExempt($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $result = $this->store->consume('ip:' . $this->context->clientIp, $this->capacity, $this->capacity / max(1, $this->perSeconds), microtime(true));
        if (!$result->allowed) {
            throw new TooManyRequests($result->retryAfterSeconds);
        }

        return $handler->handle($request)
            ->withHeader('RateLimit-Limit', (string) $this->capacity)
            ->withHeader('RateLimit-Remaining', (string) $result->remaining);
    }

    private function isExempt(string $path): bool
    {
        foreach ($this->exempt as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
