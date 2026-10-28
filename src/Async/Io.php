<?php

declare(strict_types=1);

namespace Keel\Async;

/**
 * Waiting for sockets without blocking the process: inside a task only the current fiber
 * waits; outside any task the loop is driven until the socket is ready.
 */
final class Io
{
    /**
     * @param resource $stream
     * @throws TimeoutException when $timeout seconds pass first
     */
    public static function readable($stream, ?float $timeout = null): void
    {
        self::wait($stream, false, $timeout);
    }

    /**
     * @param resource $stream
     * @throws TimeoutException when $timeout seconds pass first
     */
    public static function writable($stream, ?float $timeout = null): void
    {
        self::wait($stream, true, $timeout);
    }

    /**
     * Writes all of $bytes to a non-blocking stream, waiting for the socket to drain.
     *
     * @param resource $stream
     */
    public static function writeAll($stream, string $bytes, ?float $timeout = null): void
    {
        while ($bytes !== '') {
            $written = @fwrite($stream, $bytes);
            if ($written === false) {
                throw new \RuntimeException('The connection was closed while writing.');
            }
            $bytes = substr($bytes, $written);
            if ($bytes !== '') {
                self::writable($stream, $timeout);
            }
        }
    }

    /** @param resource $stream */
    private static function wait($stream, bool $write, ?float $timeout): void
    {
        $loop = Loop::get();
        $suspension = Suspension::create();
        $watcher = $write
            ? $loop->onWritable($stream, static fn () => $suspension->resume())
            : $loop->onReadable($stream, static fn () => $suspension->resume());
        $timer = $timeout === null ? null : $loop->delay($timeout, static fn () => $suspension->throw(new TimeoutException('Timed out waiting for the socket.')));
        $suspension->onCancel(static function () use ($loop, $watcher, $timer): void {
            $loop->cancelWatcher($watcher);
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
        });
        try {
            $suspension->suspend();
        } finally {
            $loop->cancelWatcher($watcher);
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
        }
    }
}
