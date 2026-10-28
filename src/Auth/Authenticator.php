<?php

declare(strict_types=1);

namespace Keel\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a request's credentials into a principal. Bind your own in app.php to support
 * another scheme: `->bind(Authenticator::class, MyAuthenticator::class)`.
 */
interface Authenticator
{
    /**
     * @return Principal|null null when the request carries no credentials
     * @throws \Keel\Http\Exception\Unauthorized when credentials are present but not valid
     */
    public function authenticate(ServerRequestInterface $request): ?Principal;
}
