<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Events\Listener;
use Keel\Realtime\Hub;

/** Publishes every placed order to its tenant's live channel (WebSocket and SSE subscribers). */
#[Listener]
final readonly class OrderFeed
{
    public function __construct(private Hub $hub)
    {
    }

    public static function channel(string $tenantId): string
    {
        return "orders.$tenantId";
    }

    public function __invoke(OrderPlaced $event): void
    {
        $this->hub->publish(self::channel($event->tenantId), json_encode(['orderId' => $event->orderId, 'totalCents' => $event->totalCents], \JSON_THROW_ON_ERROR));
    }
}
