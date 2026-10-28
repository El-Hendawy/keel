<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Container\Attribute\Config;
use Keel\Events\Listener;
use Keel\Webhooks\Webhooks;

/**
 * Tells the merchant about a new order with a signed webhook. Async: it runs in a queue
 * worker after the order's transaction commits, never slowing down or failing checkout.
 */
#[Listener(async: true)]
final readonly class NotifyMerchantOfOrder
{
    public function __construct(
        private Webhooks $webhooks,
        #[Config('merchants.webhook_url')] private string $url = '',
        #[Config('merchants.webhook_secret')] private string $secret = '',
    ) {
    }

    public function __invoke(OrderPlaced $event): void
    {
        if ($this->url === '') {
            return;
        }
        $this->webhooks->send($this->url, $this->secret, 'order.placed', [
            'orderId' => $event->orderId,
            'totalCents' => $event->totalCents,
        ]);
    }
}
