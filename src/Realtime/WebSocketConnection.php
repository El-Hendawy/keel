<?php

declare(strict_types=1);

namespace Keel\Realtime;

use Keel\Auth\Principal;

/** One open WebSocket, as handlers see it. */
interface WebSocketConnection
{
    public function id(): string;

    /** The authenticated caller of the handshake, or null on an anonymous endpoint. */
    public function principal(): ?Principal;

    public function tenantId(): ?string;

    /** @return array<string, scalar|null> what the endpoint passed to WebSocket::accept() */
    public function context(): array;

    public function send(string $text): void;

    public function sendBinary(string $data): void;

    /** Delivers messages published to $channel on the Hub (from any process) to this client. */
    public function join(string $channel): void;

    public function leave(string $channel): void;

    public function close(int $code = 1000, string $reason = ''): void;
}
