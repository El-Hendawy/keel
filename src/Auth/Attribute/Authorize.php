<?php

declare(strict_types=1);

namespace Keel\Auth\Attribute;

use Attribute;

/**
 * Requires scopes and, optionally, a policy for an endpoint. Compiled into the endpoint:
 * no attribute is read at runtime.
 *
 *     #[Post('/orders')]
 *     #[Authorize(scopes: ['orders:write'])]
 *
 *     #[Delete('/orders/{id}')]
 *     #[Authorize(scopes: ['orders:write'], policy: OwnsOrder::class)]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Authorize
{
    /**
     * @param list<string> $scopes
     * @param class-string<\Keel\Auth\Policy>|null $policy
     */
    public function __construct(public array $scopes = [], public ?string $policy = null)
    {
    }
}
