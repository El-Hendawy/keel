<?php

declare(strict_types=1);

namespace Keel\Redis;

use Keel\Async\Io;

/**
 * A small non-blocking Redis client (RESP2) for code running on the event loop: commands
 * suspend only the calling fiber. Used for pub/sub between server processes; use
 * RedisConnection (phpredis) elsewhere.
 *
 * One connection serves one command at a time; a subscribed connection only receives.
 */
final class AsyncRedis
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    private bool $busy = false;

    /** @var list<\Keel\Async\Suspension> */
    private array $waiting = [];

    public function __construct(private readonly string $url = 'redis://127.0.0.1:6379', private readonly float $timeout = 5.0)
    {
    }

    /**
     * Runs one command and returns its reply (string, int, null, or a list of replies).
     */
    public function command(string ...$arguments): mixed
    {
        $this->lock();
        try {
            $this->connect();
            $this->send(array_values($arguments));

            return $this->reply();
        } finally {
            $this->unlock();
        }
    }

    /**
     * Subscribes to channels or patterns (`news.*`) and calls $onMessage for every message
     * until $until returns true. Blocks the calling fiber for the duration, so run it in a task.
     *
     * @param list<string> $patterns
     * @param \Closure(string $channel, string $message): void $onMessage
     * @param (\Closure(): bool)|null $until
     */
    public function psubscribe(array $patterns, \Closure $onMessage, ?\Closure $until = null): void
    {
        $this->connect();
        $this->send(['PSUBSCRIBE', ...$patterns]);
        while ($until === null || !$until()) {
            try {
                $reply = $this->reply($until === null ? null : 0.5);
            } catch (\Keel\Async\TimeoutException) {
                continue;
            }
            if (\is_array($reply) && ($reply[0] ?? null) === 'pmessage' && \is_string($reply[2] ?? null) && \is_string($reply[3] ?? null)) {
                $onMessage($reply[2], $reply[3]);
            }
        }
        $this->close();
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }

    private function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }
        $parts = parse_url($this->url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('redis.url is not a valid URL.');
        }
        $socket = @stream_socket_client('tcp://' . $parts['host'] . ':' . ($parts['port'] ?? 6379), $errno, $error, $this->timeout, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect to Redis: $error");
        }
        stream_set_blocking($socket, false);
        Io::writable($socket, $this->timeout);
        $this->socket = $socket;
        if (isset($parts['pass'])) {
            $this->send(isset($parts['user']) ? ['AUTH', $parts['user'], $parts['pass']] : ['AUTH', $parts['pass']]);
            $this->reply();
        }
        $db = (int) ltrim($parts['path'] ?? '', '/');
        if ($db > 0) {
            $this->send(['SELECT', (string) $db]);
            $this->reply();
        }
    }

    /** @param list<string> $arguments */
    private function send(array $arguments): void
    {
        $payload = '*' . \count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $payload .= '$' . \strlen($argument) . "\r\n" . $argument . "\r\n";
        }
        \assert($this->socket !== null);
        Io::writeAll($this->socket, $payload, $this->timeout);
    }

    private function reply(?float $timeout = null): mixed
    {
        $line = $this->line($timeout ?? $this->timeout);
        $type = $line[0] ?? '';
        $rest = substr($line, 1);

        return match ($type) {
            '+' => $rest,
            '-' => throw new \RuntimeException("Redis error: $rest"),
            ':' => (int) $rest,
            '$' => (int) $rest < 0 ? null : $this->bulk((int) $rest),
            '*' => (int) $rest < 0 ? null : $this->multi((int) $rest),
            default => throw new \RuntimeException("Unexpected Redis reply \"$line\"."),
        };
    }

    /** @return list<mixed> */
    private function multi(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->reply();
        }

        return $items;
    }

    private function line(float $timeout): string
    {
        while (($end = strpos($this->buffer, "\r\n")) === false) {
            $this->fill($timeout);
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);

        return $line;
    }

    private function bulk(int $length): string
    {
        while (\strlen($this->buffer) < $length + 2) {
            $this->fill($this->timeout);
        }
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length + 2);

        return $data;
    }

    private function fill(float $timeout): void
    {
        \assert($this->socket !== null);
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            $this->close();
            throw new \RuntimeException('Redis closed the connection.');
        }
        if ($chunk === '') {
            Io::readable($this->socket, $timeout);

            return;
        }
        $this->buffer .= $chunk;
    }

    private function lock(): void
    {
        if ($this->busy) {
            $suspension = \Keel\Async\Suspension::create();
            $this->waiting[] = $suspension;
            $suspension->suspend();
        }
        $this->busy = true;
    }

    private function unlock(): void
    {
        $next = array_shift($this->waiting);
        if ($next === null) {
            $this->busy = false;
        } else {
            $next->resume(); // hand the connection over directly
        }
    }
}
