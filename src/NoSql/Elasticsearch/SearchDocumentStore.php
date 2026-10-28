<?php

declare(strict_types=1);

namespace Keel\NoSql\Elasticsearch;

use Keel\NoSql\DocumentStore;

/**
 * DocumentStore on Elasticsearch/OpenSearch: one index per collection. Writes wait until they
 * are searchable, so find() sees them; for search as a secondary index, use SearchClient directly.
 */
final readonly class SearchDocumentStore implements DocumentStore
{
    public function __construct(private SearchClient $client, private string $prefix = '')
    {
    }

    public function get(string $collection, string $id): ?array
    {
        $document = $this->client->get($this->prefix . $collection, $id);

        return $document === null ? null : ['_id' => $id] + $document;
    }

    public function put(string $collection, string $id, array $document): void
    {
        unset($document['_id']);
        $this->client->index($this->prefix . $collection, $id, $document, refresh: true);
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->client->delete($this->prefix . $collection, $id, refresh: true);
    }

    public function find(string $collection, array $equals = [], int $limit = 100): array
    {
        $filters = [];
        foreach ($equals as $field => $value) {
            $filters[] = $value === null
                ? ['bool' => ['must_not' => [['exists' => ['field' => $field]]]]]
                : ['term' => [(\is_string($value) ? "$field.keyword" : $field) => $value]];
        }
        $query = $filters === [] ? ['match_all' => new \stdClass()] : ['bool' => ['filter' => $filters]];
        $hits = $this->client->search($this->prefix . $collection, ['query' => $query, 'size' => $limit])['hits'];

        return array_map(static function (array $hit): array {
            unset($hit['_score']);

            return $hit;
        }, $hits);
    }
}
