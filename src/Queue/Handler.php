<?php

declare(strict_types=1);

namespace Keel\Queue;

use Attribute;

/**
 * Marks an invokable class as the handler of the job type of its __invoke parameter.
 * Job classes are typed DTOs, serialized with the same generated code as API types.
 *
 *     final readonly class SendReceipt { public function __construct(public string $orderId) {} }
 *
 *     #[Handler(maxAttempts: 10)]
 *     final class SendReceiptHandler { public function __invoke(SendReceipt $job): void {} }
 *
 * A handler that throws is retried with exponential backoff; after maxAttempts the job is
 * kept as failed (`keel queue:failed`, `keel queue:retry`). Handlers must be idempotent:
 * delivery is at least once.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Handler
{
    public function __construct(
        public int $maxAttempts = 5,
        public int $backoffSeconds = 10,
        public string $queue = 'default',
    ) {
    }
}
