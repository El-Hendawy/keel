<?php

declare(strict_types=1);

namespace Keel\Realtime;

/** Closes a WebSocket with a code (RFC 6455 section 7.4). */
final class WebSocketClosed extends \RuntimeException
{
    public function __construct(public readonly int $closeCode, string $reason = '')
    {
        parent::__construct($reason, $closeCode);
    }
}
