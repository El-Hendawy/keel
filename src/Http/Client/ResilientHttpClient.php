<?php

declare(strict_types=1);

namespace Keel\Http\Client;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Observability\Metrics\Metrics;
use Keel\Observability\Tracing\Span;
use Keel\Observability\Tracing\Tracer;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The default outbound client: the curl client plus a client span with trace
 * propagation, a circuit breaker and a bulkhead per host, and RED metrics.
 */
#[Singleton]
final class ResilientHttpClient implements ClientInterface
{
    private readonly CircuitBreaker $breaker;

    /** @var array<string, int> */
    private array $inFlight = [];

    public function __construct(
        private readonly CurlHttpClient $inner,
        private readonly Tracer $tracer,
        private readonly Metrics $metrics,
        #[Config('http_client.breaker_threshold')] int $threshold = 5,
        #[Config('http_client.breaker_cooldown_seconds')] float $cooldown = 30.0,
        #[Config('http_client.max_concurrent_per_host')] private readonly int $bulkhead = 20,
    ) {
        $this->breaker = new CircuitBreaker($threshold, $cooldown);
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): ResponseInterface
    {
        return $this->sendRequest(new Request('GET', $url, $headers));
    }

    /**
     * @param array<mixed> $json
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $json, array $headers = []): ResponseInterface
    {
        return $this->sendRequest(new Request('POST', $url, ['Content-Type' => 'application/json'] + $headers, json_encode($json, \JSON_THROW_ON_ERROR)));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $host = $request->getUri()->getHost();
        $now = microtime(true);
        if (!$this->breaker->allow($host, $now)) {
            $this->metrics->increment('http_client_requests_total', ['host' => $host, 'outcome' => 'circuit_open']);
            throw new CircuitOpen("Circuit open for $host: recent calls failed. Failing fast.");
        }
        if (($this->inFlight[$host] ?? 0) >= $this->bulkhead) {
            $this->metrics->increment('http_client_requests_total', ['host' => $host, 'outcome' => 'bulkhead_full']);
            throw new BulkheadFull("Too many concurrent requests to $host from this worker.");
        }

        $span = $this->tracer->startSpan('HTTP ' . $request->getMethod(), [
            'http.request.method' => $request->getMethod(),
            'server.address' => $host,
            'url.full' => (string) $request->getUri()->withUserInfo(''),
        ], Span::KIND_CLIENT);
        $request = $request->withHeader('traceparent', $span->traceparent());

        $this->inFlight[$host] = ($this->inFlight[$host] ?? 0) + 1;
        $start = hrtime(true);
        $outcome = 'error';
        try {
            $response = $this->inner->sendRequest($request);
            $status = $response->getStatusCode();
            $span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $this->breaker->failure($host, microtime(true));
                $span->error = true;
                $outcome = '5xx';
            } else {
                $this->breaker->success($host);
                $outcome = $status >= 400 ? '4xx' : 'ok';
            }

            return $response;
        } catch (\Throwable $e) {
            $this->breaker->failure($host, microtime(true));
            $span->recordException($e);
            throw $e;
        } finally {
            $this->inFlight[$host]--;
            $span->end();
            $this->metrics->increment('http_client_requests_total', ['host' => $host, 'outcome' => $outcome], help: 'Outbound HTTP requests.');
            $this->metrics->observe('http_client_duration_seconds', (hrtime(true) - $start) / 1e9, ['host' => $host], help: 'Outbound HTTP latency.');
        }
    }
}
