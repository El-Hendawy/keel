<?php

declare(strict_types=1);

namespace Keel\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/** Response constructors used by generated endpoints and middleware. */
final class Responses
{
    public const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data === [] ? new \stdClass() : $data, self::JSON_FLAGS),
        );
    }

    public static function empty(int $status = 204): ResponseInterface
    {
        return new Response($status);
    }

    public static function text(string $body, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): ResponseInterface
    {
        return new Response($status, ['Content-Type' => $contentType], $body);
    }

    /**
     * RFC 9457 problem details.
     *
     * @param array<string, mixed> $extensions
     * @param array<string, string> $headers
     */
    public static function problem(int $status, string $title, string $detail = '', string $type = 'about:blank', array $extensions = [], array $headers = []): ResponseInterface
    {
        $body = ['type' => $type, 'title' => $title, 'status' => $status];
        if ($detail !== '') {
            $body['detail'] = $detail;
        }
        $body += $extensions;

        return new Response(
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
            json_encode($body, self::JSON_FLAGS),
        );
    }
}
