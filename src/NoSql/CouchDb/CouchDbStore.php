<?php

declare(strict_types=1);

namespace Keel\NoSql\CouchDb;

use Keel\NoSql\DocumentStore;

/** DocumentStore on CouchDB: one database per collection; `_rev` is managed for you and not returned. */
final readonly class CouchDbStore implements DocumentStore
{
    public function __construct(private CouchDbClient $client, private string $prefix = '')
    {
    }

    public function get(string $collection, string $id): ?array
    {
        $document = $this->client->get($this->prefix . $collection, $id);
        unset($document['_rev']);

        return $document;
    }

    public function put(string $collection, string $id, array $document): void
    {
        $this->client->put($this->prefix . $collection, $id, $document);
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->client->delete($this->prefix . $collection, $id);
    }

    public function find(string $collection, array $equals = [], int $limit = 100): array
    {
        return array_map(static function (array $document): array {
            unset($document['_rev']);

            return $document;
        }, $this->client->find($this->prefix . $collection, $equals, $limit));
    }
}
