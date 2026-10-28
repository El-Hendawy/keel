<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Scoped;
use Keel\Http\RequestContext;
use Keel\Http\TrustedProxies;
use Keel\Support\IdGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Assigns a request id (accepting a well-formed incoming X-Request-Id) and resolves the client address. */
#[Scoped]
final readonly class RequestContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestContext $context,
        private IdGenerator $ids,
        private TrustedProxies $proxies,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine('X-Request-Id');
        $this->context->requestId = preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming) === 1 ? $incoming : $this->ids->generate();
        $this->context->clientIp = $this->proxies->clientIp($request);

        return $handler->handle($request)->withHeader('X-Request-Id', $this->context->requestId);
    }
}
