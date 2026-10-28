<?php

declare(strict_types=1);

namespace Keel\Http\Idempotency;

/** An existing claim on an idempotency key. A null response means the first request is still running. */
final readonly class IdempotencyRecord
{
    public function __construct(public string $fingerprint, public ?StoredResponse $response)
    {
    }
}
