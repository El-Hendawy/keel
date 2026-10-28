<?php

declare(strict_types=1);

namespace Keel\Events;

use Attribute;

/**
 * Marks an invokable class as a listener for the event type of its __invoke parameter.
 * Listener maps are compiled; a listener without a lifetime attribute is Transient.
 *
 *     #[Listener]
 *     final class CreateInvoice { public function __invoke(OrderPlaced $event): void {} }
 *
 * An async listener runs later, in a queue worker, with retries. Dispatching the event
 * pushes a job through the Queue, so with ->withQueue() and a transaction it is part of
 * the transactional outbox. The event class must then be a typed, serializable DTO.
 *
 *     #[Listener(async: true)]
 *     final class NotifyMerchant { public function __invoke(OrderPlaced $event): void {} }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Listener
{
    public function __construct(public bool $async = false)
    {
    }
}
