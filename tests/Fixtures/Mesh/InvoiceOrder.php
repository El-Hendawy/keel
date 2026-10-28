<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

use Keel\Routing\Attribute\Post;
use Keel\Service\Services;

/** Orders' side: asks billing for an invoice. */
#[Post('/orders/{id}/invoice')]
final class InvoiceOrder
{
    public function __invoke(string $id, Services $services): CallFacts
    {
        $facts = $services->post('billing', '/invoices', ['orderId' => $id]);
        \assert(\is_array($facts));

        return new CallFacts(
            (string) $facts['caller'],
            (string) $facts['kind'],
            \is_string($facts['tenant']) ? $facts['tenant'] : null,
            (string) $facts['requestId'],
            \is_int($facts['timeoutMs']) ? $facts['timeoutMs'] : null,
            (string) $facts['orderId'],
        );
    }
}
