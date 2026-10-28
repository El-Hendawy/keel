<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Scoped;
use Keel\Http\RequestContext;
use Keel\Observability\Metrics\Metrics;
use Keel\Observability\Tracing\Span;
use Keel\Observability\Tracing\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * A server span per request (continuing an incoming W3C traceparent), RED metrics
 * per route and one structured access log line.
 */
#[Scoped]
final readonly class TelemetryMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Tracer $tracer,
        private Metrics $metrics,
        private RequestContext $context,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $traceparent = $request->getHeaderLine('traceparent');
        $span = $this->tracer->startSpan('HTTP ' . $method, [
            'http.request.method' => $method,
            'url.path' => $request->getUri()->getPath(),
            'client.address' => $this->context->clientIp,
            'http.request_id' => $this->context->requestId,
        ], Span::KIND_SERVER, $traceparent !== '' ? $traceparent : null);

        $status = 500;
        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();

            return $response->withHeader('traceparent', $span->traceparent());
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $route = $this->context->route ?? 'unmatched';
            $duration = microtime(true) - $this->context->startedAt;
            $span->name = $method . ' ' . $route;
            $span->setAttribute('http.route', $route);
            $span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $span->error = true;
            }
            $span->end();

            $labels = ['method' => $method, 'route' => $route, 'status' => (string) $status];
            $this->metrics->increment('http_requests_total', $labels, help: 'HTTP requests handled.');
            $this->metrics->observe('http_request_duration_seconds', $duration, ['method' => $method, 'route' => $route], help: 'HTTP request latency.');

            $this->logger->info('{method} {route} {status} {duration_ms}ms', [
                'method' => $method,
                'route' => $route,
                'path' => $request->getUri()->getPath(),
                'status' => $status,
                'duration_ms' => round($duration * 1000, 2),
                'request_id' => $this->context->requestId,
                'client_ip' => $this->context->clientIp,
            ]);
        }
    }
}
