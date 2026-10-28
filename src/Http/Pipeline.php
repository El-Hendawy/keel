<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Container\Scope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs PSR-15 middleware in order, resolving each from the request scope.
 * Keeps the scope's ServerRequestInterface in sync with the request being handled.
 */
final class Pipeline implements RequestHandlerInterface
{
    private int $index = 0;

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function __construct(
        private readonly array $middleware,
        private readonly Scope $scope,
        private readonly RequestHandlerInterface $final,
        private readonly ErrorRenderer $errors,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->scope->set(ServerRequestInterface::class, $request);

        if (!isset($this->middleware[$this->index])) {
            return $this->final->handle($request);
        }

        $middleware = $this->scope->get($this->middleware[$this->index]);
        $next = clone $this;
        $next->index++;

        try {
            return $middleware->process($request, $next);
        } catch (\Throwable $error) {
            // Render here so outer middleware (telemetry, request id) still sees a response.
            return $this->errors->render($error, $this->scope->get(RequestContext::class)->requestId);
        }
    }
}
