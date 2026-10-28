<?php

declare(strict_types=1);

namespace Keel\Async;

/**
 * Runs operations concurrently and returns their results in the same order (or with
 * the same string keys). Structured: when one fails or the timeout expires, the others
 * are cancelled, and the call only returns or throws once every operation has settled.
 *
 *     [$user, $orders] = await_all(
 *         fn () => $users->find($id),
 *         fn () => $http->get('/fx/rates'),
 *         timeout: 2.0,
 *     );
 *
 * @param (\Closure(): mixed)|float|null ...$operations closures to run; `timeout:` in seconds
 * @return array<array-key, mixed>
 */
function await_all(\Closure|float|null ...$operations): array
{
    $timeout = $operations['timeout'] ?? null;
    unset($operations['timeout']);
    if ($timeout instanceof \Closure) {
        throw new \InvalidArgumentException('timeout must be a number of seconds.');
    }

    $closures = [];
    foreach ($operations as $key => $operation) {
        if (!$operation instanceof \Closure) {
            throw new \InvalidArgumentException("Operation \"$key\" must be a closure.");
        }
        $closures[$key] = $operation;
    }

    return AwaitAll::run($closures, $timeout);
}

/**
 * Starts an operation concurrently. Await it with $task->await(); prefer await_all(),
 * which cannot leak tasks.
 *
 * @param \Closure(): mixed $operation
 */
function async(\Closure $operation): Task
{
    return Task::start($operation);
}

/** Non-blocking sleep inside a task; an ordinary sleep elsewhere. */
function delay(float $seconds): void
{
    $loop = Loop::get();
    $suspension = Suspension::create();
    $timer = $loop->delay($seconds, static fn () => $suspension->resume());
    $suspension->onCancel(static fn () => $loop->cancelTimer($timer));
    $suspension->suspend();
}
