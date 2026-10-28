<?php

declare(strict_types=1);

namespace Keel\Realtime;

/** @internal marks a response as "upgrade this connection to a WebSocket"; the Keel server acts on it */
final class UpgradeBody extends \Keel\Http\Streaming\StreamingBody
{
    /**
     * @param class-string<WebSocketHandler> $handler
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public readonly string $handler,
        public readonly array $context,
        public readonly ?string $protocol,
        public readonly ?\Keel\Auth\Principal $principal,
    ) {
        parent::__construct(static function (): void {
        });
    }
}
