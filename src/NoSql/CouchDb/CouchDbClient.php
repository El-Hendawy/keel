<?php

declare(strict_types=1);

namespace Keel\NoSql\CouchDb;

use Keel\NoSql\JsonHttp;
use Psr\Http\Client\ClientInterface;

/**
 * Apache CouchDB (and compatible servers such as IBM Cloudant) over HTTP.
 *
 *     $couch->put('orders', $id, ['status' => 'open']);          // creates or updates (revisions handled)
 *     $couch->find('orders', ['status' => 'open'], limit: 50);  // Mango query
 */
final readonly class CouchDbClient
{
    private JsonHttp $json;

    public function __construct(ClientInterface $http, string $url)
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("\"$url\" is not a valid CouchDB URL.");
        }
        $headers = isset($parts['user']) ? ['Authorization' => 'Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''))] : [];
        $base = ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
        $this->json = new JsonHttp($http, $base, $headers, 'CouchDB');
    }

    /** @return array<string, mixed>|null the document with `_id` and `_rev` */
    public function get(string $database, string $id): ?array
    {
        [$status, $body] = $this->json->request('GET', '/' . rawurlencode($database) . '/' . rawurlencode($id), allow: [404]);

        /** @var array<string, mixed>|null */
        return $status === 404 || !\is_array($body) ? null : $body;
    }

    /**
     * Creates or replaces a document, fetching the current revision first. Retries once when a
     * concurrent writer wins the race.
     *
     * @param array<string, mixed> $document
     * @return string the new revision
     */
    public function put(string $database, string $id, array $document): string
    {
        unset($document['_id']);
        for ($attempt = 0; ; $attempt++) {
            $current = $this->get($database, $id);
            $body = ['_id' => $id] + $document;
            if ($current !== null && \is_string($current['_rev'] ?? null)) {
                $body['_rev'] = $current['_rev'];
            } else {
                unset($body['_rev']);
            }
            [$status, $result] = $this->json->request('PUT', '/' . rawurlencode($database) . '/' . rawurlencode($id), $body, allow: [409]);
            if ($status !== 409) {
                return \is_array($result) && \is_string($result['rev'] ?? null) ? $result['rev'] : '';
            }
            if ($attempt >= 1) {
                throw new \Keel\NoSql\NoSqlException("CouchDB: document $id in $database keeps changing (409 conflict).", 409);
            }
        }
    }

    public function delete(string $database, string $id): bool
    {
        $current = $this->get($database, $id);
        if ($current === null || !\is_string($current['_rev'] ?? null)) {
            return false;
        }
        [$status] = $this->json->request('DELETE', '/' . rawurlencode($database) . '/' . rawurlencode($id) . '?rev=' . rawurlencode($current['_rev']), allow: [404, 409]);

        return $status < 300;
    }

    /**
     * A Mango query (`_find`).
     *
     * @param array<string, mixed> $selector
     * @param array<string, mixed> $options sort, fields, skip, use_index
     * @return list<array<string, mixed>>
     */
    public function find(string $database, array $selector = [], int $limit = 100, array $options = []): array
    {
        [, $result] = $this->json->request('POST', '/' . rawurlencode($database) . '/_find', ['selector' => $selector === [] ? new \stdClass() : $selector, 'limit' => $limit] + $options);
        $docs = \is_array($result) && \is_array($result['docs'] ?? null) ? $result['docs'] : [];

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($docs, \is_array(...)));
    }

    public function createDatabase(string $database): void
    {
        $this->json->request('PUT', '/' . rawurlencode($database), allow: [412]);
    }

    public function deleteDatabase(string $database): void
    {
        $this->json->request('DELETE', '/' . rawurlencode($database), allow: [404]);
    }
}
