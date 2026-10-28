<?php

declare(strict_types=1);

namespace Keel\Events;

use Keel\Container\Attribute\Scoped;
use Keel\Container\Scope;
use Keel\Queue\Queue;
use Keel\Runtime\BuildManifest;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 dispatcher over the compiled listener map. Listeners run synchronously in the
 * current scope; listeners registered for a parent class or interface also receive the event.
 * Async listeners are pushed to the queue after the synchronous ones have run.
 */
#[Scoped]
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private Scope $scope, private BuildManifest $manifest, private Queue $queue)
    {
    }

    public function dispatch(object $event): object
    {
        foreach ($this->manifest->listenersFor($event::class) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $callable = $this->scope->get($listener);
            \assert(\is_callable($callable));
            $callable($event);
        }
        foreach ($this->manifest->asyncListenersFor($event::class) as $type) {
            $this->queue->push($event, type: $type);
        }

        return $event;
    }
}
