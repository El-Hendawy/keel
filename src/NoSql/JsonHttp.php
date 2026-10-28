<?php

declare(strict_types=1);

namespace Keel\NoSql;

use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * JSON over HTTP for the HTTP-based databases. Through Keel's client, calls are traced,
 * protected by a circuit breaker, and non-blocking under `keel run`.
 *
 * @internal
 */
final readonly class JsonHttp
{
    /** @param array<string, string> $headers sent with every request */
    public function __construct(private ClientInterface $http, private string $base, private array $headers = [], private string $name = 'database')
    {
    }

    /**
     * @param array<mixed>|object|string|null $body arrays and objects are sent as JSON, strings as is
     * @param array<string, string> $headers
     * @param list<int> $allow statuses that are not errors (besides 2xx)
     * @return array{0: int, 1: mixed} the status and the decoded JSON body (the text when not JSON, null when empty)
     */
    public function request(string $method, string $path, array|object|string|null $body = null, array $headers = [], array $allow = [], ?\Closure $sign = null): array
    {
        $payload = match (true) {
            $body === null => '',
            \is_string($body) => $body,
            default => json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION),
        };
        $request = new Request($method, rtrim($this->base, '/') . $path, $headers + ($body === null || \is_string($body) ? [] : ['Content-Type' => 'application/json']) + $this->headers + ['Accept' => 'application/json'], $payload);
        if ($sign !== null) {
            $signed = $sign($request);
            \assert($signed instanceof RequestInterface);
            $request = $signed;
        }
        try {
            $response = $this->http->sendRequest($request);
        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            throw new NoSqlException("{$this->name} is unreachable at {$this->base}: " . $e->getMessage(), 0, '', $e);
        }
        $status = $response->getStatusCode();
        $text = (string) $response->getBody();
        $json = str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json') || ($text !== '' && \in_array($text[0], ['{', '['], true));
        $decoded = $text === '' ? null : ($json ? json_decode($text, true, 512, \JSON_BIGINT_AS_STRING) : $text);
        if (($status < 200 || $status >= 300) && !\in_array($status, $allow, true)) {
            $reason = \is_array($decoded) ? self::reason($decoded) : substr($text, 0, 200);
            throw new NoSqlException("{$this->name} answered $status to $method $path: $reason", $status, \is_array($decoded) && \is_string($decoded['__type'] ?? null) ? $decoded['__type'] : '');
        }

        return [$status, $decoded];
    }

    /** @param array<mixed> $error */
    private static function reason(array $error): string
    {
        foreach (['message', 'Message', 'reason', 'error'] as $key) {
            $value = $error[$key] ?? null;
            if (\is_string($value)) {
                return $value;
            }
            if (\is_array($value)) {
                return self::reason($value) ?: (string) json_encode($value);
            }
        }

        return (string) json_encode($error);
    }
}
