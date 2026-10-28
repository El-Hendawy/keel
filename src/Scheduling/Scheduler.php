<?php

declare(strict_types=1);

namespace Keel\Scheduling;

use Keel\Container\Container;
use Keel\Locks\LockStore;
use Keel\Runtime\BuildManifest;
use Psr\Log\LoggerInterface;

/**
 * Runs the scheduled tasks that are due at a given minute. Each task runs in its own
 * scope, under a lock named after the task and the minute, so with several nodes running
 * the scheduler a task still runs exactly once per due minute.
 */
final readonly class Scheduler
{
    public function __construct(private Container $container)
    {
    }

    /** @return list<class-string> the tasks that ran */
    public function runDue(\DateTimeImmutable $now): array
    {
        $manifest = $this->container->get(BuildManifest::class);
        $locks = $this->container->get(LockStore::class);
        $logger = $this->container->get(LoggerInterface::class);
        $minute = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i');
        $ran = [];
        foreach ($manifest->schedule() as ['class' => $class, 'cron' => $cron]) {
            if (!CronExpression::parse($cron)->isDue($now)) {
                continue;
            }
            if ($locks->acquire("schedule:$class:$minute", 3600) === null) {
                continue; // another node has it
            }
            $scope = $this->container->createScope();
            try {
                $task = $scope->get($class);
                \assert(\is_callable($task));
                $task();
                $ran[] = $class;
            } catch (\Throwable $e) {
                $logger->error('Scheduled task {task} failed: {error}', ['task' => $class, 'error' => $e->getMessage(), 'exception' => $e]);
            } finally {
                $scope->dispose();
            }
        }

        return $ran;
    }
}
