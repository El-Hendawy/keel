<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Http\RequestContext;
use Keel\Tenancy\TenantContext;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Calls to the other services of a system, by name.
 *
 *     'services' => ['billing' => ['url' => 'http://billing.internal', 'scopes' => ['invoices:write']]],
 *
 *     $invoice = $services->post('billing', '/invoices', ['orderId' => $order->id]);
 *
 * Every call carries, without code at the call site:
 * - credentials for the target (ServiceTokens; the current tenant travels inside the token),
 * - the trace (`traceparent`) and the request id (`X-Request-Id`), so logs and traces join up,
 * - the caller's remaining time (`Keel-Timeout-Ms`), so a service never works for a caller that
 *   has given up; a call after the deadline fails at once with 504.
 * Calls go through Keel's HTTP client: circuit breaker and bulkhead per host, RED metrics, and
 * non-blocking under `keel run` (run several at once with await_all()).
 */
#[Scoped]
final readonly class Services
{
    /** @param array<mixed> $services */
    public function __construct(
        private ClientInterface $http,
        private ServiceTokens $tokens,
        private RequestContext $context,
        private TenantContext $tenant,
        #[Config('services')] private array $services = [],
    ) {
    }

    /**
     * Sends a request and returns the decoded JSON body of a 2xx response (null when empty).
     *
     * @param array<mixed>|null $json
     * @param array<string, string> $headers
     * @throws ServiceCallFailed for any other outcome
     */
    public function call(string $service, string $method, string $path, ?array $json = null, array $headers = []): mixed
    {
        $response = $this->send($service, $method, $path, $json, $headers);
        $body = (string) $response->getBody();
        $decoded = $body === '' ? null : json_decode($body, true);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $detail = \is_array($decoded) && \is_string($decoded['detail'] ?? null) ? $decoded['detail'] : $response->getReasonPhrase();
            throw new ServiceCallFailed($service, $status, "$service answered $status to $method $path: $detail", \is_array($decoded) ? $decoded : null);
        }

        return $decoded;
    }

    /** @param array<string, string> $headers */
    public function get(string $service, string $path, array $headers = []): mixed
    {
        return $this->call($service, 'GET', $path, null, $headers);
    }

    /**
     * @param array<mixed> $json
     * @param array<string, string> $headers
     */
    public function post(string $service, string $path, array $json = [], array $headers = []): mixed
    {
        return $this->call($service, 'POST', $path, $json, $headers);
    }

    /**
     * The raw response, whatever its status, for callers that handle statuses themselves.
     *
     * @param array<mixed>|null $json
     * @param array<string, string> $headers
     */
    public function send(string $service, string $method, string $path, ?array $json = null, array $headers = []): ResponseInterface
    {
        $config = $this->services[$service] ?? null;
        $base = \is_array($config) && \is_string($config['url'] ?? null) ? $config['url'] : throw new \InvalidArgumentException("Unknown service \"$service\": add services.$service.url to the configuration.");
        $scopes = \is_array($config['scopes'] ?? null) ? array_values(array_filter($config['scopes'], \is_string(...))) : [];
        $audience = \is_string($config['audience'] ?? null) ? $config['audience'] : $service;

        if ($this->context->deadline !== null) {
            $remaining = (int) floor(($this->context->deadline - microtime(true)) * 1000);
            if ($remaining <= 0) {
                throw new ServiceCallFailed($service, 504, "Not calling $service: the caller's deadline has passed.");
            }
            $headers += ['Keel-Timeout-Ms' => (string) $remaining];
        }
        if ($this->context->requestId !== '') {
            $headers += ['X-Request-Id' => $this->context->requestId];
        }
        $authorization = $this->tokens->authorization($audience, $this->tenant->current(), $scopes);
        if ($authorization !== null) {
            $headers += ['Authorization' => $authorization];
        }
        $headers += ['Accept' => 'application/json', 'User-Agent' => 'Keel-Services/1.0'];
        if ($json !== null) {
            $headers += ['Content-Type' => 'application/json'];
        }
        $body = $json === null ? null : json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);

        try {
            return $this->http->sendRequest(new Request($method, rtrim($base, '/') . '/' . ltrim($path, '/'), $headers, $body));
        } catch (\Psr\Http\Client\ClientExceptionInterface|\Keel\Http\Client\CircuitOpen|\Keel\Http\Client\BulkheadFull $e) {
            throw new ServiceCallFailed($service, 0, "$service is unavailable: " . $e->getMessage(), null, $e);
        }
    }
}
