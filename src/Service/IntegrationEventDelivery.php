<?php

declare(strict_types=1);

namespace Keel\Service;

/** One CloudEvent on its way to one subscriber; a queued job. */
final readonly class IntegrationEventDelivery
{
    public function __construct(
        public string $eventId,
        public string $type,
        public string $url,
        public string $audience,
        public string $body,
    ) {
    }
}
