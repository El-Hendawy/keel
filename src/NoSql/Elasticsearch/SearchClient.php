<?php

declare(strict_types=1);

namespace Keel\NoSql\Elasticsearch;

use Keel\NoSql\JsonHttp;
use Psr\Http\Client\ClientInterface;

/**
 * Elasticsearch and OpenSearch over their REST API (the subset both share).
 *
 *     $search->index('products', $id, ['name' => 'Keel mug', 'price' => 12.5]);
 *     $hits = $search->search('products', ['query' => ['match' => ['name' => 'mug']]]);
 *
 * Authentication: basic auth in the URL (https://user:pass@host:9200) or an API key
 * (`Authorization: ApiKey ...`).
 */
final readonly class SearchClient
{
    private JsonHttp $json;

    public function __construct(ClientInterface $http, string $url, ?string $apiKey = null)
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("\"$url\" is not a valid Elasticsearch/OpenSearch URL.");
        }
        $headers = [];
        if (isset($parts['user'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''));
        }
        if ($apiKey !== null && $apiKey !== '') {
            $headers['Authorization'] = 'ApiKey ' . $apiKey;
        }
        $base = ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
        $this->json = new JsonHttp($http, $base, $headers, 'Elasticsearch');
    }

    /**
     * @param array<string, mixed> $document
     * @param bool $refresh wait until the document is searchable (slower; for tests and read-after-write)
     */
    public function index(string $index, string $id, array $document, bool $refresh = false): void
    {
        $this->json->request('PUT', '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id) . ($refresh ? '?refresh=wait_for' : ''), $document === [] ? new \stdClass() : $document);
    }

    /** @return array<string, mixed>|null the document's source */
    public function get(string $index, string $id): ?array
    {
        [$status, $body] = $this->json->request('GET', '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id), allow: [404]);
        if ($status === 404 || !\is_array($body) || !\is_array($body['_source'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> */
        return $body['_source'];
    }

    public function delete(string $index, string $id, bool $refresh = false): bool
    {
        [$status] = $this->json->request('DELETE', '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id) . ($refresh ? '?refresh=wait_for' : ''), allow: [404]);

        return $status !== 404;
    }

    /**
     * Runs a search (Query DSL) and returns the hits' sources with `_id` and `_score`.
     *
     * @param array<string, mixed> $body
     * @return array{total: int, hits: list<array<string, mixed>>, aggregations: array<string, mixed>}
     */
    public function search(string $index, array $body): array
    {
        [$status, $result] = $this->json->request('POST', '/' . rawurlencode($index) . '/_search', $body === [] ? new \stdClass() : $body, allow: [404]);
        if ($status === 404 || !\is_array($result)) {
            return ['total' => 0, 'hits' => [], 'aggregations' => []];
        }
        $hits = [];
        $outer = \is_array($result['hits'] ?? null) ? $result['hits'] : [];
        foreach (\is_array($outer['hits'] ?? null) ? $outer['hits'] : [] as $hit) {
            if (\is_array($hit) && \is_array($hit['_source'] ?? null)) {
                $document = ['_id' => $hit['_id'] ?? null, '_score' => $hit['_score'] ?? null];
                foreach ($hit['_source'] as $field => $value) {
                    $document[(string) $field] ??= $value;
                }
                $hits[] = $document;
            }
        }
        $total = $outer['total'] ?? 0;
        $total = \is_array($total) ? ($total['value'] ?? 0) : $total;
        /** @var array<string, mixed> $aggregations */
        $aggregations = \is_array($result['aggregations'] ?? null) ? $result['aggregations'] : [];

        return ['total' => \is_int($total) ? $total : 0, 'hits' => $hits, 'aggregations' => $aggregations];
    }

    /**
     * Indexes many documents in one request.
     *
     * @param array<string, array<string, mixed>> $documents id => document
     */
    public function bulk(string $index, array $documents, bool $refresh = false): void
    {
        $lines = '';
        foreach ($documents as $id => $document) {
            $lines .= json_encode(['index' => ['_index' => $index, '_id' => (string) $id]], \JSON_THROW_ON_ERROR) . "\n"
                . json_encode($document === [] ? new \stdClass() : $document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION) . "\n";
        }
        [, $result] = $this->json->request('POST', '/_bulk' . ($refresh ? '?refresh=wait_for' : ''), $lines, ['Content-Type' => 'application/x-ndjson']);
        if (\is_array($result) && ($result['errors'] ?? false) === true) {
            throw new \Keel\NoSql\NoSqlException('Elasticsearch rejected part of a bulk request: ' . json_encode($result['items'] ?? []));
        }
    }

    /** @param array<string, mixed> $settings mappings and settings, e.g. ['mappings' => ['properties' => [...]]] */
    public function createIndex(string $index, array $settings = []): void
    {
        $this->json->request('PUT', '/' . rawurlencode($index), $settings === [] ? new \stdClass() : $settings);
    }

    public function deleteIndex(string $index): void
    {
        $this->json->request('DELETE', '/' . rawurlencode($index), allow: [404]);
    }

    /**
     * Any other endpoint.
     *
     * @param array<mixed>|null $body
     */
    public function raw(string $method, string $path, ?array $body = null): mixed
    {
        return $this->json->request($method, $path, $body)[1];
    }
}
