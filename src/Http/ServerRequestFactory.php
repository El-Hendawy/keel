<?php

declare(strict_types=1);

namespace Keel\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/** Builds a PSR-7 request from PHP superglobals (FPM, CGI, FrankenPHP, built-in server). */
final class ServerRequestFactory
{
    /**
     * @param array<string, mixed>|null $server
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $cookies
     */
    public static function fromGlobals(?array $server = null, ?array $query = null, ?array $cookies = null, ?string $body = null): ServerRequestInterface
    {
        $server ??= $_SERVER;
        $method = \is_string($server['REQUEST_METHOD'] ?? null) ? strtoupper($server['REQUEST_METHOD']) : 'GET';
        $https = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
        $host = \is_string($server['HTTP_HOST'] ?? null) ? $server['HTTP_HOST'] : (\is_string($server['SERVER_NAME'] ?? null) ? $server['SERVER_NAME'] : 'localhost');
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $protocol = \is_string($server['SERVER_PROTOCOL'] ?? null) ? str_replace('HTTP/', '', $server['SERVER_PROTOCOL']) : '1.1';

        $headers = [];
        foreach ($server as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        $factory = new Psr17Factory();
        $stream = $factory->createStream($body ?? (string) file_get_contents('php://input'));

        $request = new ServerRequest($method, ($https ? 'https' : 'http') . '://' . $host . $uri, $headers, $stream, $protocol, $server);

        return $request
            ->withQueryParams($query ?? $_GET)
            ->withCookieParams($cookies ?? $_COOKIE);
    }
}
