<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Container\Container;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handles one request: opens a scope, runs the middleware pipeline and the route,
 * then disposes the scope. The container and route table are shared across
 * requests; nothing else is.
 */
final readonly class HttpKernel implements RequestHandlerInterface
{
    /**
     * @param class-string $endpoints
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    public function __construct(
        private Container $container,
        private Router $router,
        private string $endpoints,
        private array $middleware,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->container->createScope();
        $errors = $this->container->get(ErrorRenderer::class);

        try {
            $scope->set(ServerRequestInterface::class, $request);
            $final = new RouteDispatcher($this->router, $this->endpoints, $scope, $errors);

            return (new Pipeline($this->middleware, $scope, $final, $errors))->handle($request);
        } catch (\Throwable $error) {
            return $errors->render($error);
        } finally {
            $scope->dispose();
        }
    }

    public function router(): Router
    {
        return $this->router;
    }
}
