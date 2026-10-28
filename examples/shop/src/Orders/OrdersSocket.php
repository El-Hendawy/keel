<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Realtime\WebSocketConnection;
use Keel\Realtime\WebSocketHandler;

/** Pushes the tenant's new orders to the browser; answers "ping" with "pong". */
final readonly class OrdersSocket implements WebSocketHandler
{
    public function onOpen(WebSocketConnection $connection): void
    {
        $connection->join(OrderFeed::channel((string) $connection->tenantId()));
    }

    public function onMessage(WebSocketConnection $connection, string $message, bool $binary): void
    {
        if ($message === 'ping') {
            $connection->send('pong');
        }
    }

    public function onClose(WebSocketConnection $connection, int $code, string $reason): void
    {
    }
}
