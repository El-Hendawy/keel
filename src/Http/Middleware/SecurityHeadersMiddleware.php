<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Safe defaults for an API: no sniffing, no framing, no caching unless a response opts in. */
#[Singleton]
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(#[Config('security.hsts')] private bool $hsts = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
        ];
        if ($this->hsts) {
            $defaults['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains';
        }
        foreach ($defaults as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
