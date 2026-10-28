<?php

declare(strict_types=1);

namespace Keel\Async;

/**
 * A point where code waits for a result. Inside a task the fiber is suspended and other
 * tasks run; outside any task the loop is driven until the result arrives, so the same
 * code works in both places.
 */
final class Suspension
{
    private bool $settled = false;
    private bool $ready = false;
    private mixed $value = null;
    private ?\Throwable $error = null;

    /** @var list<\Closure(): void> */
    private array $cleanup = [];

    private function __construct(private readonly ?Task $task)
    {
    }

    public static function create(): self
    {
        $task = Task::current();
        $suspension = new self($task);
        $task?->waitOn($suspension);

        return $suspension;
    }

    /** Registers a cleanup to run if this wait is cancelled, such as removing a timer. */
    public function onCancel(\Closure $cleanup): void
    {
        $this->cleanup[] = $cleanup;
    }

    public function suspend(): mixed
    {
        if ($this->task !== null) {
            if (!$this->ready) {
                \Fiber::suspend();
            }
        } else {
            Loop::get()->run(fn (): bool => $this->ready);
        }
        $this->task?->waitOn(null);

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    public function resume(mixed $value = null): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->value = $value;
        $this->wake();
    }

    public function throw(\Throwable $error): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->error = $error;
        $this->wake();
    }

    public function cancel(\Throwable $reason): void
    {
        if ($this->settled) {
            return;
        }
        foreach ($this->cleanup as $cleanup) {
            $cleanup();
        }
        $this->cleanup = [];
        $this->throw($reason);
    }

    private function wake(): void
    {
        $task = $this->task;
        Loop::get()->defer(function () use ($task): void {
            $this->ready = true;
            $task?->resumeFiber();
        });
    }
}
