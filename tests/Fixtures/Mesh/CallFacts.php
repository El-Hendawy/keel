<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

final readonly class CallFacts
{
    public function __construct(
        public string $caller,
        public string $kind,
        public ?string $tenant,
        public string $requestId,
        public ?int $timeoutMs,
        public string $orderId,
    ) {
    }
}
