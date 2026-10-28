<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\BadRequest;
use Keel\Http\RequestBody;
use Keel\Http\Responses;
use Keel\Http\Toon\Toon;
use Keel\Http\Toon\ToonException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Speaks TOON as well as JSON (->withToon()).
 *
 * - Request bodies sent as `Content-Type: text/toon` are decoded into the same data a JSON
 *   body would give, so the generated hydrators validate them identically.
 * - Responses use the format the client asks for in `Accept` (`text/toon` or
 *   `application/json`); when it accepts both or says nothing, `http.default_format` decides.
 *   Problem documents follow the same rule.
 */
#[Singleton]
final readonly class ContentNegotiationMiddleware implements MiddlewareInterface
{
    private string $delimiter;

    public function __construct(
        #[Config('http.default_format')] private string $default = 'json',
        #[Config('toon.delimiter')] string $delimiter = ',',
    ) {
        $this->delimiter = match ($delimiter) {
            ',', "\t", '|' => $delimiter,
            default => throw new \InvalidArgumentException('toon.delimiter must be ",", "|" or a tab.'),
        };
        if (!\in_array($default, ['json', 'toon'], true)) {
            throw new \InvalidArgumentException("http.default_format must be \"json\" or \"toon\", got \"$default\".");
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (str_starts_with(strtolower($request->getHeaderLine('Content-Type')), Toon::MEDIA_TYPE)) {
            $request = $this->decodeBody($request);
        }
        $response = $handler->handle($request);
        if ($this->wantsToon($request->getHeaderLine('Accept'))) {
            return $this->toToon($response);
        }

        return $response;
    }

    public function wantsToon(string $accept): bool
    {
        if ($accept === '') {
            return $this->default === 'toon';
        }
        $toon = $json = 0.0;
        foreach (explode(',', strtolower($accept)) as $range) {
            $parts = array_map(trim(...), explode(';', $range));
            $q = 1.0;
            foreach (\array_slice($parts, 1) as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $q = (float) substr($parameter, 2);
                }
            }
            match ($parts[0]) {
                Toon::MEDIA_TYPE, 'text/*' => $toon = max($toon, $q),
                'application/json', 'application/problem+json', 'application/*' => $json = max($json, $q),
                default => null,
            };
        }
        // Equal preference (both, neither, or only */*): the configured default decides.
        return $toon === $json ? $this->default === 'toon' : $toon > $json;
    }

    private function decodeBody(ServerRequestInterface $request): ServerRequestInterface
    {
        try {
            $data = Toon::decode((string) $request->getBody(), maxDepth: RequestBody::MAX_DEPTH);
        } catch (ToonException $e) {
            throw new BadRequest('The request body is not valid TOON: ' . $e->getMessage(), previous: $e);
        }
        if (!\is_array($data)) {
            throw new BadRequest('The request body must be a TOON object or array.');
        }

        return $request->withParsedBody($data)->withAttribute(RequestBody::PRE_PARSED, true);
    }

    private function toToon(ResponseInterface $response): ResponseInterface
    {
        $type = strtolower($response->getHeaderLine('Content-Type'));
        if ($type !== 'application/json' && $type !== 'application/problem+json') {
            return $response;
        }
        $data = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        $toon = Toon::encode($data, $this->delimiter);

        return $response
            ->withHeader('Content-Type', Toon::MEDIA_TYPE . '; charset=utf-8' . ($type === 'application/problem+json' ? '; profile=problem' : ''))
            ->withBody(Stream::create($toon))
            ->withAddedHeader('Vary', 'Accept');
    }
}
