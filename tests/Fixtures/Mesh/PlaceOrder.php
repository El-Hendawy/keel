<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

use Keel\Database\Database;
use Keel\Routing\Attribute\Post;
use Keel\Service\IntegrationEvents;

/** Publishes order.placed through the outbox, inside the transaction. */
#[Post('/orders/{id}/place')]
final class PlaceOrder
{
    public function __invoke(string $id, Database $db, IntegrationEvents $events): Placed
    {
        return new Placed($db->transaction(static fn (): string => $events->publish('order.placed', ['orderId' => $id], subject: $id)));
    }
}
