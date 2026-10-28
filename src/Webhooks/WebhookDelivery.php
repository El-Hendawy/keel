<?php

declare(strict_types=1);

namespace Keel\Webhooks;

/** The queued job for one webhook message. The body is fixed when it is sent; it is signed per attempt. */
final readonly class WebhookDelivery
{
    public function __construct(
        public string $id,
        public string $url,
        public string $secret,
        public string $type,
        public string $body,
    ) {
    }
}
