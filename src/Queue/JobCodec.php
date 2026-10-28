<?php

declare(strict_types=1);

namespace Keel\Queue;

use Keel\Container\Attribute\Singleton;
use Keel\Http\Responses;
use Keel\Runtime\BuildManifest;

/**
 * Turns jobs into JSON and back with the generated serializers and hydrators, so a job
 * is validated when it is read just as an API input is. No `unserialize()`: a row in the
 * jobs table can never instantiate an arbitrary class.
 */
#[Singleton]
final readonly class JobCodec
{
    public function __construct(private BuildManifest $manifest)
    {
    }

    /** @return array{class: class-string, handler: class-string, hydrator: string, serializer: string, queue: string, maxAttempts: int, backoff: int} */
    public function definition(string $type): array
    {
        return $this->manifest->job($type) ?? throw new \LogicException("No #[Handler] for job type $type. Add a handler class, or check that it is in a scanned directory.");
    }

    public function encode(string $type, object $job): string
    {
        $definition = $this->definition($type);
        if (!$job instanceof $definition['class']) {
            throw new \InvalidArgumentException("Job type $type carries {$definition['class']}, got " . $job::class . '.');
        }
        $serialize = [$this->manifest->serializers(), $definition['serializer']];
        \assert(\is_callable($serialize));
        $data = $serialize($job);

        return json_encode($data, Responses::JSON_FLAGS);
    }

    public function decode(string $type, string $payload): object
    {
        $definition = $this->definition($type);
        $hydrate = [$this->manifest->hydrators(), $definition['hydrator']];
        \assert(\is_callable($hydrate));
        $job = $hydrate(json_decode($payload, true, 64, \JSON_THROW_ON_ERROR));
        \assert(\is_object($job));

        return $job;
    }
}
