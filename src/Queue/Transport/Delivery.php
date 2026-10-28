<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

/** A received job and the broker's handle for acknowledging it. */
final readonly class Delivery
{
    /** @param int $attempts how many times it has been delivered, including this time */
    public function __construct(public Envelope $envelope, public string $handle, public int $attempts)
    {
    }
}
