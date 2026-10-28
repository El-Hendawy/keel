<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Container\Scope;
use Keel\Tenancy\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates the request if it carries credentials. Whether a route *requires* a
 * principal is decided per route and compiled into the endpoint (deny by default once
 * ->withAuth() is on; opt out with #[Anonymous]). The principal's tenant becomes the
 * current tenant.
 *
 * Paths in `auth.exempt` (the health and metrics endpoints) are skipped: they have
 * their own protection and must work for probes and scrapers. The authenticator is only
 * built when a request carries credentials, so anonymous traffic pays nothing for it.
 */
#[Scoped]
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    /** @param list<string> $exempt path prefixes */
    public function __construct(
        private Scope $scope,
        private AuthContext $context,
        private TenantContext $tenant,
        #[Config('auth.exempt')] private array $exempt = ['/health/', '/metrics'],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->exempt as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $handler->handle($request);
            }
        }
        if (!$request->hasHeader('Authorization')) {
            return $handler->handle($request);
        }
        $principal = $this->scope->get(Authenticator::class)->authenticate($request);
        if ($principal !== null) {
            $this->context->set($principal);
            if ($principal->tenantId !== null) {
                $this->tenant->set($principal->tenantId);
            }
        }

        return $handler->handle($request);
    }
}
