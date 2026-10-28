<?php

declare(strict_types=1);

namespace Keel\Auth\Oidc;

/**
 * A started login: send the user to $url, and keep state, nonce and verifier (server-side
 * or in a short-lived, encrypted, HttpOnly cookie) to finish it when the user comes back.
 */
final readonly class LoginRequest
{
    public function __construct(public string $url, public string $state, public string $nonce, public string $verifier)
    {
    }
}
