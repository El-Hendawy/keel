<?php

declare(strict_types=1);

namespace Keel\Server;

/** Limits and timeouts of the Keel server (`server.*` in config.php). */
final readonly class ServerOptions
{
    public function __construct(
        public int $maxHeaderBytes = 16_384,
        public int $maxBodyBytes = 1_048_576,
        public float $headerTimeout = 10.0,
        public float $bodyTimeout = 30.0,
        public float $keepAliveTimeout = 5.0,
        public int $maxConnections = 900,
        public int $maxWebSocketMessageBytes = 1_048_576,
        public float $webSocketPingSeconds = 30.0,
        public float $shutdownGraceSeconds = 30.0,
        public int $maxRequests = 0,
    ) {
    }

    /** @param array<string, mixed> $config flattened configuration */
    public static function fromConfig(array $config): self
    {
        $int = static fn (string $key, int $default): int => is_numeric($config[$key] ?? null) ? (int) $config[$key] : $default;
        $float = static fn (string $key, float $default): float => is_numeric($config[$key] ?? null) ? (float) $config[$key] : $default;

        return new self(
            $int('server.max_header_bytes', 16_384),
            $int('http.max_body_bytes', 1_048_576),
            $float('server.header_timeout', 10.0),
            $float('server.body_timeout', 30.0),
            $float('server.keep_alive_timeout', 5.0),
            $int('server.max_connections', 900),
            $int('websocket.max_message_bytes', 1_048_576),
            $float('websocket.ping_seconds', 30.0),
            $float('server.shutdown_grace_seconds', 30.0),
            $int('server.max_requests', (int) (getenv('KEEL_MAX_REQUESTS') ?: 0)),
        );
    }
}
