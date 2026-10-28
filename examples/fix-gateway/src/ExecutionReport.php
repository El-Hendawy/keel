<?php

declare(strict_types=1);

namespace FixGateway;

use Keel\Fix\Attribute\Field;
use Keel\Fix\Attribute\FixMessage;

#[FixMessage('8')]
final readonly class ExecutionReport
{
    public function __construct(
        #[Field(37)] public string $orderId,
        #[Field(11)] public string $clOrdId,
        #[Field(17)] public string $execId,
        #[Field(150)] public ExecType $execType,
        #[Field(39)] public ExecType $ordStatus,
        #[Field(55)] public string $symbol,
        #[Field(54)] public Side $side,
        #[Field(151)] public int $leavesQty,
        #[Field(14)] public int $cumQty,
        #[Field(58)] public ?string $text = null,
    ) {
    }
}
