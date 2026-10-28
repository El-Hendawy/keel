<?php

declare(strict_types=1);

namespace Keel\Async;

/**
 * A unit of concurrent work running in its own fiber.
 * Prefer await_all(): it guarantees that no task outlives the code that started it.
 */
final class Task
{
    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, Task>|null */
    private static ?\WeakMap $byFiber = null;

    /** @var \Fiber<mixed, mixed, mixed, mixed> */
    private \Fiber $fiber;

    private bool $done = false;
    private mixed $result = null;
    private ?\Throwable $error = null;
    private ?Suspension $waitingOn = null;

    /** @var list<\Closure(Task): void> */
    private array $listeners = [];

    private function __construct()
    {
    }

    /** @param \Closure(): mixed $operation */
    public static function start(\Closure $operation): self
    {
        $task = new self();
        $task->fiber = new \Fiber(static function () use ($task, $operation): void {
            try {
                $task->result = $operation();
            } catch (\Throwable $e) {
                $task->error = $e;
            }
            $task->complete();
        });
        self::$byFiber ??= new \WeakMap();
        self::$byFiber[$task->fiber] = $task;

        Loop::get()->defer(static function () use ($task): void {
            if (!$task->done) {
                $task->fiber->start();
            }
        });

        return $task;
    }

    public static function current(): ?self
    {
        $fiber = \Fiber::getCurrent();

        return $fiber !== null ? (self::$byFiber[$fiber] ?? null) : null;
    }

    /** Waits for this task and returns its result or rethrows its error. */
    public function await(): mixed
    {
        if (!$this->done) {
            $suspension = Suspension::create();
            $this->onDone(static fn () => $suspension->resume());
            $suspension->onCancel(fn () => $this->cancel(new CancelledException('The awaiting task was cancelled.')));
            $suspension->suspend();
        }
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }

    public function cancel(\Throwable $reason): void
    {
        if ($this->done) {
            return;
        }
        if (!$this->fiber->isStarted()) {
            $this->error = $reason;
            $this->complete();

            return;
        }
        $this->waitingOn?->cancel($reason);
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function error(): ?\Throwable
    {
        return $this->error;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    /** @param \Closure(Task): void $listener */
    public function onDone(\Closure $listener): void
    {
        if ($this->done) {
            $listener($this);

            return;
        }
        $this->listeners[] = $listener;
    }

    /** @internal */
    public function waitOn(?Suspension $suspension): void
    {
        $this->waitingOn = $suspension;
    }

    /** @internal */
    public function resumeFiber(): void
    {
        if ($this->fiber->isSuspended()) {
            $this->fiber->resume();
        }
    }

    private function complete(): void
    {
        $this->done = true;
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            $listener($this);
        }
    }
}
