<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Container\Scope;
use Keel\Http\Exception\MethodNotAllowed;
use Keel\Http\Exception\NotFound;
use Keel\Routing\RouteResult;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The innermost handler: matches the route and calls its compiled dispatch method.
 * Errors are rendered here so that every middleware sees a real status code.
 */
final readonly class RouteDispatcher implements RequestHandlerInterface
{
    /** @param class-string $endpoints generated class holding one static method per route */
    public function __construct(
        private Router $router,
        private string $endpoints,
        private Scope $scope,
        private ErrorRenderer $errors,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->scope->get(RequestContext::class);

        try {
            $result = $this->router->match($request->getMethod(), $request->getUri()->getPath());
            if ($result->status === RouteResult::NOT_FOUND) {
                throw new NotFound();
            }
            if ($result->status === RouteResult::METHOD_NOT_ALLOWED) {
                throw new MethodNotAllowed($result->allowed);
            }

            $route = $this->router->route($result->route);
            $context->route = $route['path'];
            $handler = [$this->endpoints, $route['handler']];
            \assert(\is_callable($handler));

            /** @var ResponseInterface */
            return $handler($this->scope, $request, $result->params);
        } catch (\Throwable $error) {
            return $this->errors->render($error, $context->requestId);
        }
    }
}
