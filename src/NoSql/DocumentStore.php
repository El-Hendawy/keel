<?php

declare(strict_types=1);

namespace Keel\NoSql;

/**
 * Documents by id in named collections: the part every document database shares, so code
 * written against it runs on MongoDB, DynamoDB, CouchDB, Elasticsearch/OpenSearch or in memory.
 *
 * Documents are JSON-shaped arrays. `_id` is the document's id: put() sets it and every read
 * returns it. Use the database's own client (MongoClient, DynamoDbClient, ...) for anything
 * beyond this: queries on ranges, aggregation, indexes, transactions.
 */
interface DocumentStore
{
    /** @return array<string, mixed>|null */
    public function get(string $collection, string $id): ?array;

    /**
     * Creates or replaces the document.
     *
     * @param array<string, mixed> $document
     */
    public function put(string $collection, string $id, array $document): void;

    /** @return bool whether a document was deleted */
    public function delete(string $collection, string $id): bool;

    /**
     * Documents whose top-level fields equal the given values (all of them), at most $limit.
     *
     * @param array<string, scalar|null> $equals
     * @return list<array<string, mixed>>
     */
    public function find(string $collection, array $equals = [], int $limit = 100): array;
}
