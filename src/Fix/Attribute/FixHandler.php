<?php

declare(strict_types=1);

namespace Keel\Fix\Attribute;

use Attribute;

/**
 * Handles inbound application messages of the type of its first __invoke parameter, in a
 * fresh scope per message:
 *
 *     #[FixHandler]
 *     final readonly class AcceptOrder
 *     {
 *         public function __invoke(NewOrderSingle $order, FixContext $fix): void
 *         {
 *             $fix->reply(new ExecutionReport(...));
 *         }
 *     }
 *
 * Messages without a handler get a BusinessMessageReject (reason 3, unsupported message type).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FixHandler
{
}
