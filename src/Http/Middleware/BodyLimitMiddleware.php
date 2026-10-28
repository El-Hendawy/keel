<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\PayloadTooLarge;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Singleton]
final readonly class BodyLimitMiddleware implements MiddlewareInterface
{
    public function __construct(#[Config('http.max_body_bytes')] private int $maxBytes = 1_048_576)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $declared = $request->getHeaderLine('Content-Length');
        $size = $declared !== '' ? (int) $declared : $request->getBody()->getSize();
        if ($size !== null && $size > $this->maxBytes) {
            throw new PayloadTooLarge("Request bodies are limited to {$this->maxBytes} bytes.");
        }

        return $handler->handle($request);
    }
}
