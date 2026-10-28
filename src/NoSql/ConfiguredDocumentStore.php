<?php

declare(strict_types=1);

namespace Keel\NoSql;

use Keel\Container\Attribute\Singleton;

/** The DocumentStore chosen by `nosql.store`; bound by ->withDocumentStore(). */
#[Singleton]
final readonly class ConfiguredDocumentStore implements DocumentStore
{
    public function __construct(private NoSqlConnections $connections)
    {
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->connections->store()->get($collection, $id);
    }

    public function put(string $collection, string $id, array $document): void
    {
        $this->connections->store()->put($collection, $id, $document);
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->connections->store()->delete($collection, $id);
    }

    public function find(string $collection, array $equals = [], int $limit = 100): array
    {
        return $this->connections->store()->find($collection, $equals, $limit);
    }
}
