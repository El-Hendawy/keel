<?php

declare(strict_types=1);

namespace Keel\Async;

/**
 * The state machine behind await_all(): starts every operation, tracks completion,
 * and on the first failure or the timeout cancels the rest, then waits for all of
 * them to settle before returning or throwing.
 */
final class AwaitAll
{
    /** @var array<array-key, Task> */
    private array $tasks = [];

    private int $remaining = 0;

    private ?\Throwable $failure = null;

    private ?int $timer = null;

    private readonly Suspension $all;

    /** @param array<array-key, \Closure(): mixed> $operations */
    private function __construct(array $operations, private readonly ?float $timeout)
    {
        $this->all = Suspension::create();
        foreach ($operations as $key => $operation) {
            $this->tasks[$key] = Task::start($operation);
        }
        $this->remaining = \count($this->tasks);
    }

    /**
     * @param array<array-key, \Closure(): mixed> $operations
     * @return array<array-key, mixed>
     */
    public static function run(array $operations, ?float $timeout): array
    {
        if ($operations === []) {
            return [];
        }

        return (new self($operations, $timeout))->wait();
    }

    /** @return array<array-key, mixed> */
    private function wait(): array
    {
        $loop = Loop::get();
        foreach ($this->tasks as $task) {
            $task->onDone($this->settled(...));
        }
        if ($this->timeout !== null) {
            $this->timer = $loop->delay($this->timeout, $this->timedOut(...));
        }
        // If the caller is itself cancelled, cancel the children and wait for them.
        $this->all->onCancel(fn () => $this->cancelAll(new CancelledException('The parent operation was cancelled.')));

        $this->all->suspend();

        return array_map(static fn (Task $task): mixed => $task->result(), $this->tasks);
    }

    private function settled(Task $task): void
    {
        $error = $task->error();
        if ($error !== null && $this->failure === null) {
            $this->failure = $error;
            $this->cancelAll($error);
        }
        if (--$this->remaining > 0) {
            return;
        }
        if ($this->timer !== null) {
            Loop::get()->cancelTimer($this->timer);
            $this->timer = null;
        }
        $this->failure === null ? $this->all->resume() : $this->all->throw($this->failure);
    }

    private function timedOut(): void
    {
        $this->timer = null;
        if ($this->failure === null) {
            $this->failure = new TimeoutException(\sprintf('Operations did not complete within %.3fs.', $this->timeout));
            $this->cancelAll($this->failure);
        }
    }

    private function cancelAll(\Throwable $reason): void
    {
        foreach ($this->tasks as $task) {
            $task->cancel(new CancelledException($reason->getMessage(), 0, $reason));
        }
    }
}
