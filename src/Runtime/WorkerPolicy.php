<?php

declare(strict_types=1);

namespace Keel\Runtime;

/**
 * When a long-running worker should stop and let the supervisor replace it:
 * after N requests, above a memory ceiling, or after SIGTERM/SIGINT (graceful).
 */
final class WorkerPolicy
{
    private bool $stopRequested = false;

    public function __construct(
        public readonly int $maxRequests = 1000,
        public readonly int $maxMemoryBytes = 256 * 1024 * 1024,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $requests = getenv('KEEL_MAX_REQUESTS');
        $memory = getenv('KEEL_MAX_MEMORY_MB');

        return new self(
            \is_string($requests) && $requests !== '' ? (int) $requests : 1000,
            (\is_string($memory) && $memory !== '' ? (int) $memory : 256) * 1024 * 1024,
        );
    }

    /** Installs signal handlers so the current request finishes before the worker exits. */
    public function listenForSignals(?\Closure $onStop = null): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function () use ($onStop): void {
            $this->stopRequested = true;
            if ($onStop !== null) {
                $onStop();
            }
        };
        pcntl_signal(\SIGTERM, $handler);
        pcntl_signal(\SIGINT, $handler);
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function shouldStop(int $handled): bool
    {
        return $this->stopRequested
            || ($this->maxRequests > 0 && $handled >= $this->maxRequests)
            || memory_get_usage(true) >= $this->maxMemoryBytes;
    }
}
