<?php

declare(strict_types=1);

namespace Keel\Async;

/**
 * A minimal event loop driving fibers: deferred callbacks, timers, socket readiness
 * (stream_select) and curl_multi.
 *
 * The loop is the one piece of process-wide infrastructure that is deliberately
 * global, like the PHP engine itself. It holds no application state: every task it
 * runs belongs to a scope and completes before that scope closes (structured
 * concurrency), so nothing leaks into the next request.
 */
final class Loop
{
    private static ?self $instance = null;

    /** @var \SplQueue<\Closure(): void> */
    private \SplQueue $queue;

    /** @var array<int, array{0: float, 1: \Closure(): void}> */
    private array $timers = [];

    private int $nextTimerId = 0;

    private ?\CurlMultiHandle $multi = null;

    /** @var array<int, array{0: \CurlHandle, 1: \Closure(\CurlHandle, int): void}> */
    private array $curl = [];

    private bool $running = false;

    /** @var array<int, array{0: resource, 1: \Closure(): void}> watcher id => [stream, callback] */
    private array $readers = [];

    /** @var array<int, array{0: resource, 1: \Closure(): void}> */
    private array $writers = [];

    private function __construct()
    {
        $this->queue = new \SplQueue();
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /** @param \Closure(): void $callback */
    public function defer(\Closure $callback): void
    {
        $this->queue->enqueue($callback);
    }

    /** @param \Closure(): void $callback */
    public function delay(float $seconds, \Closure $callback): int
    {
        $id = ++$this->nextTimerId;
        $this->timers[$id] = [microtime(true) + max(0.0, $seconds), $callback];

        return $id;
    }

    public function cancelTimer(int $id): void
    {
        unset($this->timers[$id]);
    }

    /**
     * Calls $callback every time $stream is readable, until cancelWatcher().
     *
     * @param resource $stream
     * @param \Closure(): void $callback
     */
    public function onReadable($stream, \Closure $callback): int
    {
        $id = ++$this->nextTimerId;
        $this->readers[$id] = [$stream, $callback];

        return $id;
    }

    /**
     * Calls $callback every time $stream is writable, until cancelWatcher().
     *
     * @param resource $stream
     * @param \Closure(): void $callback
     */
    public function onWritable($stream, \Closure $callback): int
    {
        $id = ++$this->nextTimerId;
        $this->writers[$id] = [$stream, $callback];

        return $id;
    }

    public function cancelWatcher(int $id): void
    {
        unset($this->readers[$id], $this->writers[$id]);
    }

    /** True while the loop is being driven (by run(), or by a server's main loop). */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /** @param \Closure(\CurlHandle, int): void $onDone receives the handle and the CURLE_* result code */
    public function addCurl(\CurlHandle $handle, \Closure $onDone): void
    {
        $this->multi ??= curl_multi_init();
        $this->curl[spl_object_id($handle)] = [$handle, $onDone];
        curl_multi_add_handle($this->multi, $handle);
    }

    public function removeCurl(\CurlHandle $handle): void
    {
        $id = spl_object_id($handle);
        if (isset($this->curl[$id]) && $this->multi !== null) {
            curl_multi_remove_handle($this->multi, $handle);
            unset($this->curl[$id]);
        }
    }

    /**
     * Runs the loop until $done returns true.
     *
     * @param \Closure(): bool $done
     */
    public function run(\Closure $done): void
    {
        if ($this->running) {
            throw new \LogicException('The event loop is already running. Blocking calls inside loop callbacks are not allowed.');
        }
        $this->running = true;
        try {
            while (!$done()) {
                if (!$this->tick()) {
                    throw new \LogicException('Deadlock: the awaited operation can never complete because nothing else is scheduled.');
                }
            }
        } finally {
            $this->running = false;
        }
    }

    /** @return bool false when there is nothing left to do */
    private function tick(): bool
    {
        $ready = \count($this->queue);
        for ($i = 0; $i < $ready; $i++) {
            ($this->queue->dequeue())();
        }
        if (!$this->queue->isEmpty()) {
            return true;
        }

        $timeout = null;
        if ($this->timers !== []) {
            $next = min(array_column($this->timers, 0));
            $timeout = max(0.0, $next - microtime(true));
        }

        if ($this->readers !== [] || $this->writers !== []) {
            $this->pollStreams($this->curl !== [] ? min($timeout ?? 0.01, 0.01) : ($timeout ?? 1.0));
            if ($this->curl !== []) {
                $this->pollCurl(0.0);
            }
        } elseif ($this->curl !== []) {
            $this->pollCurl($timeout ?? 1.0);
        } elseif ($timeout !== null) {
            if ($timeout > 0) {
                usleep((int) ($timeout * 1e6));
            }
        } elseif ($ready === 0) {
            return false; // nothing ran and nothing is pending
        } else {
            return true; // callbacks ran; let the caller re-check its condition
        }

        $now = microtime(true);
        foreach ($this->timers as $id => [$at, $callback]) {
            if ($at <= $now) {
                unset($this->timers[$id]);
                $callback();
            }
        }

        return true;
    }

    private function pollStreams(float $timeout): void
    {
        $read = array_map(static fn (array $w) => $w[0], $this->readers);
        $write = array_map(static fn (array $w) => $w[0], $this->writers);
        $except = [];
        $readable = array_values($read);
        $writable = array_values($write);
        $seconds = (int) $timeout;
        $micro = (int) (($timeout - $seconds) * 1e6);
        if (@stream_select($readable, $writable, $except, $seconds, $micro) === false) {
            return; // interrupted by a signal
        }
        foreach ($this->readers as $id => [$stream, $callback]) {
            if (\in_array($stream, $readable, true) && \array_key_exists($id, $this->readers)) { // an earlier callback may have cancelled it
                $callback();
            }
        }
        foreach ($this->writers as $id => [$stream, $callback]) {
            if (\in_array($stream, $writable, true) && \array_key_exists($id, $this->writers)) {
                $callback();
            }
        }
    }

    private function pollCurl(float $timeout): void
    {
        $multi = $this->multi;
        if ($multi === null) {
            return;
        }
        $this->execCurl($multi);
        if ($this->curl !== [] && $this->queue->isEmpty()) {
            if (curl_multi_select($multi, min($timeout, 0.25)) === -1) {
                usleep(1000);
            }
            $this->execCurl($multi);
        }
    }

    private function execCurl(\CurlMultiHandle $multi): void
    {
        do {
            $status = curl_multi_exec($multi, $active);
        } while ($status === \CURLM_CALL_MULTI_PERFORM);

        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'] ?? null;
            $result = $info['result'] ?? null;
            if (!$handle instanceof \CurlHandle || !\is_int($result) || !isset($this->curl[spl_object_id($handle)])) {
                continue;
            }
            $callback = $this->curl[spl_object_id($handle)][1];
            curl_multi_remove_handle($multi, $handle);
            unset($this->curl[spl_object_id($handle)]);
            $callback($handle, $result);
        }
    }
}
