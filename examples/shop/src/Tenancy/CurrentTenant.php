<?php

declare(strict_types=1);

namespace Shop\Tenancy;

use Keel\Container\Attribute\Scoped;
use Keel\Http\Exception\BadRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The tenant of the current request. Scoped: one per request, never shared.
 *
 * This example reads a header to stay short. A real application derives the tenant
 * from the authenticated principal, never from a header the client controls.
 */
#[Scoped]
final readonly class CurrentTenant
{
    public string $id;

    public function __construct(ServerRequestInterface $request)
    {
        $id = $request->getHeaderLine('X-Tenant-Id');
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new BadRequest('Send the tenant id as a UUID in the X-Tenant-Id header.');
        }
        $this->id = strtolower($id);
    }
}
