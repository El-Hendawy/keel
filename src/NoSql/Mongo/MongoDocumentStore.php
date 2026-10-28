<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

use Keel\NoSql\DocumentStore;

/** DocumentStore on MongoDB: one collection per collection, string `_id`s. */
final readonly class MongoDocumentStore implements DocumentStore
{
    public function __construct(private MongoClient $client, private ?string $database = null)
    {
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->client->collection($collection, $this->database)->findOne(['_id' => $id]);
    }

    public function put(string $collection, string $id, array $document): void
    {
        $this->client->collection($collection, $this->database)->replaceOne(['_id' => $id], ['_id' => $id] + $document, upsert: true);
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->client->collection($collection, $this->database)->deleteOne(['_id' => $id]) > 0;
    }

    public function find(string $collection, array $equals = [], int $limit = 100): array
    {
        return $this->client->collection($collection, $this->database)->find($equals, ['limit' => $limit]);
    }
}
