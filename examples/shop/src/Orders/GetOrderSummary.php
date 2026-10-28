<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Http\Exception\NotFound;
use Keel\Auth\Attribute\Authorize;
use Keel\Routing\Attribute\Get;

use function Keel\Async\await_all;

/** Order total converted to another currency; the lookup and the FX call run concurrently. */
#[Get('/orders/{id}/summary', name: 'orders.summary')]
#[Authorize(scopes: ['orders:read'])]
final class GetOrderSummary
{
    public function __invoke(string $id, SummaryQuery $query, Orders $orders, FxRates $fx): OrderSummary
    {
        [$order, $rate] = await_all(
            fn () => $orders->find($id),
            fn () => $fx->rate($query->currency),
            timeout: 2.0,
        );
        if (!$order instanceof Order) {
            throw new NotFound("Order $id does not exist.");
        }
        \assert(\is_float($rate));

        return new OrderSummary($order->id, $order->totalCents, $query->currency, $rate, (int) round($order->totalCents * $rate));
    }
}
