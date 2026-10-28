<?php

declare(strict_types=1);

namespace Keel\Fix\Attribute;

use Attribute;

/**
 * Declares a typed FIX message. Constructor parameters with #[Field] or #[Group] are its
 * fields; the codec is generated at build time, like API hydrators.
 *
 *     #[FixMessage('D')]
 *     final readonly class NewOrderSingle
 *     {
 *         public function __construct(
 *             #[Field(11)] public string $clOrdId,
 *             #[Field(55)] public string $symbol,
 *             #[Field(54)] public Side $side,
 *             #[Field(38)] public int $orderQty,
 *             #[Field(40)] public OrdType $ordType,
 *             #[Field(44)] public ?string $price = null,
 *         ) {}
 *     }
 *
 * A non-nullable field without a default is required: a message without it is rejected
 * with SessionRejectReason 1 (required tag missing).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FixMessage
{
    public function __construct(public string $type)
    {
    }
}
