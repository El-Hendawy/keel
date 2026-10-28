<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Container\Attribute\Scoped;
use Keel\Http\Exception\Forbidden;
use Keel\Http\Exception\Unauthorized;

/**
 * The authenticated principal of the current scope, if any.
 *
 * Set by AuthenticationMiddleware for HTTP requests and by the queue worker for jobs.
 * Endpoints receive it by declaring a `Principal` (or `?Principal`) parameter; the
 * scope and policy checks of #[Authorize] are compiled into the endpoint.
 */
#[Scoped]
final class AuthContext
{
    private ?Principal $principal = null;

    public function set(Principal $principal): void
    {
        $this->principal = $principal;
    }

    public function user(): ?Principal
    {
        return $this->principal;
    }

    public function principal(): Principal
    {
        return $this->principal ?? throw self::unauthenticated();
    }

    /**
     * Requires an authenticated principal holding every scope in $scopes.
     *
     * @param list<string> $scopes
     */
    public function require(array $scopes = []): Principal
    {
        $principal = $this->principal();
        $missing = $principal->missingScopes($scopes);
        if ($missing !== []) {
            throw new Forbidden('This credential lacks the required scope(s): ' . implode(', ', $missing) . '.', ['requiredScopes' => $missing]);
        }

        return $principal;
    }

    public static function unauthenticated(string $detail = 'Authentication is required.'): Unauthorized
    {
        return new Unauthorized($detail, headers: ['WWW-Authenticate' => 'Bearer realm="api"']);
    }
}
