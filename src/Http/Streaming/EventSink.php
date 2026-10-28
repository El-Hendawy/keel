<?php

declare(strict_types=1);

namespace Keel\Http\Streaming;

/** Sends server-sent events to one client. */
final class EventSink
{
    private bool $closed = false;

    /** @param \Closure(string): void $write */
    public function __construct(private readonly \Closure $write)
    {
    }

    /**
     * Sends one event. Multi-line data is split into `data:` lines, as the format requires.
     *
     * @throws ClientDisconnected when the client has gone away
     */
    public function send(string $data, ?string $event = null, ?string $id = null): void
    {
        $frame = '';
        if ($event !== null) {
            $frame .= 'event: ' . self::line($event) . "\n";
        }
        if ($id !== null) {
            $frame .= 'id: ' . self::line($id) . "\n";
        }
        foreach (preg_split('/\r\n|\r|\n/', $data) ?: [''] as $line) {
            $frame .= 'data: ' . $line . "\n";
        }
        $this->write($frame . "\n");
    }

    /** Sends a JSON-encoded event. */
    public function json(mixed $data, ?string $event = null, ?string $id = null): void
    {
        $this->send(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $event, $id);
    }

    /** A comment line: keeps proxies from closing an idle stream. */
    public function heartbeat(): void
    {
        $this->write(": keep-alive\n\n");
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function write(string $frame): void
    {
        try {
            ($this->write)($frame);
        } catch (ClientDisconnected $e) {
            $this->closed = true;
            throw $e;
        }
    }

    private static function line(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
