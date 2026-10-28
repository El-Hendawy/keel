<?php

declare(strict_types=1);

namespace Keel\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A route-level authorization rule, used with #[Authorize(policy: ...)]. Policies are
 * services, so they can depend on repositories. Returning false yields 403.
 */
interface Policy
{
    /** @param array<string, string> $params raw path parameters */
    public function allows(Principal $principal, ServerRequestInterface $request, array $params): bool;
}
