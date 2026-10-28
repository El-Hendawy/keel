<?php

declare(strict_types=1);

namespace FixGateway;

use Keel\Fix\Attribute\Field;
use Keel\Fix\Attribute\FixMessage;
use Keel\Fix\Attribute\Group;

#[FixMessage('D')]
final readonly class NewOrderSingle
{
    /** @param list<Party> $parties */
    public function __construct(
        #[Field(11)] public string $clOrdId,
        #[Field(55)] public string $symbol,
        #[Field(54)] public Side $side,
        #[Field(38)] public int $orderQty,
        #[Field(40)] public OrdType $ordType,
        #[Field(60)] public \DateTimeImmutable $transactTime,
        #[Field(44)] public ?string $price = null,
        #[Group(count: 453, of: Party::class)] public array $parties = [],
    ) {
    }
}
