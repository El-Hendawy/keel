<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Auth\Attribute\Authorize;
use Keel\Http\Streaming\EventSink;
use Keel\Http\Streaming\EventStream;
use Keel\Realtime\Hub;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;
use Shop\Tenancy\CurrentTenant;

/** New orders of your tenant as server-sent events (needs `keel run`). */
#[Get('/orders/live', name: 'orders.live')]
#[Authorize(scopes: ['orders:read'])]
final class LiveOrders
{
    public function __invoke(Hub $hub, CurrentTenant $tenant): ResponseInterface
    {
        $channel = OrderFeed::channel($tenant->id);

        return EventStream::response(static fn (EventSink $events) => $hub->stream($channel, $events, 'order.placed'));
    }
}
