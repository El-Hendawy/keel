<?php

declare(strict_types=1);

namespace Keel\Auth\Token;

/** The result of issuing a token. `$token` is shown once and never stored. */
final readonly class IssuedToken
{
    public function __construct(public string $id, public string $token, public ?int $expiresAt)
    {
    }
}
