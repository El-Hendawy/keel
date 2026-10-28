<?php

declare(strict_types=1);

namespace Keel\Events;

use Attribute;

/**
 * Marks an invokable class as a listener for the event type of its __invoke parameter.
 * Listener maps are compiled; a listener without a lifetime attribute is Transient.
 *
 *     #[Listener]
 *     final class SendReceipt { public function __invoke(OrderPlaced $event): void {} }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Listener
{
}
