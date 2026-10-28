<?php

declare(strict_types=1);

namespace FixGateway;

use Keel\Fix\Attribute\FixHandler;
use Keel\Fix\FixContext;
use Keel\Support\IdGenerator;

/** Acknowledges every order, rejecting limit orders without a price. */
#[FixHandler]
final readonly class AcceptOrder
{
    public function __construct(private IdGenerator $ids)
    {
    }

    public function __invoke(NewOrderSingle $order, FixContext $fix): void
    {
        $rejected = $order->ordType === OrdType::Limit && $order->price === null;
        $status = $rejected ? ExecType::Rejected : ExecType::New;
        $fix->reply(new ExecutionReport(
            orderId: $this->ids->generate(),
            clOrdId: $order->clOrdId,
            execId: $this->ids->generate(),
            execType: $status,
            ordStatus: $status,
            symbol: $order->symbol,
            side: $order->side,
            leavesQty: $rejected ? 0 : $order->orderQty,
            cumQty: 0,
            text: $rejected ? 'Limit orders need a price (44).' : null,
        ));
    }
}
