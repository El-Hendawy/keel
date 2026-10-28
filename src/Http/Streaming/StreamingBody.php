<?php

declare(strict_types=1);

namespace Keel\Http\Streaming;

use Psr\Http\Message\StreamInterface;

/**
 * A response body produced while it is sent (server-sent events, long downloads). It is not
 * buffered: the server (or the SAPI emitter) runs the producer and writes each chunk as it
 * comes. Middleware that needs the whole body leaves streaming responses alone.
 */
class StreamingBody implements StreamInterface
{
    /** @param \Closure(\Closure(string): void): void $producer receives a write function */
    public function __construct(private readonly \Closure $producer)
    {
    }

    /** @param \Closure(string): void $write throws ClientDisconnected once the client is gone */
    public function produce(\Closure $write): void
    {
        ($this->producer)($write);
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('A streaming body cannot be sought.');
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('A streaming body is written by its producer.');
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
