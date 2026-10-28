<?php

declare(strict_types=1);

namespace Keel\Realtime;

/**
 * Handles one kind of WebSocket connection. Implementations are services (Transient by
 * default) resolved from a fresh scope for each event, so they can depend on the Database,
 * the ORM or anything scoped, with the connection's principal and tenant in place.
 */
interface WebSocketHandler
{
    public function onOpen(WebSocketConnection $connection): void;

    public function onMessage(WebSocketConnection $connection, string $message, bool $binary): void;

    public function onClose(WebSocketConnection $connection, int $code, string $reason): void;
}
