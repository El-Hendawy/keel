<?php

declare(strict_types=1);

namespace Keel\NoSql\Neo4j;

use Keel\NoSql\JsonHttp;
use Keel\NoSql\NoSqlException;
use Psr\Http\Client\ClientInterface;

/**
 * Neo4j (graph) over its HTTP transactional endpoint: Cypher with bound parameters.
 *
 *     $neo4j->run('MERGE (a:Account {id: $from})-[:SENT {amount: $amount}]->(b:Account {id: $to})', [...]);
 *     $rows = $neo4j->run('MATCH (a:Account {id: $id})-[:SENT*1..3]->(b) RETURN DISTINCT b.id AS id', ['id' => 'acc_1']);
 *
 * Each call is one auto-committed transaction; runAll() sends several statements atomically.
 */
final readonly class Neo4jClient
{
    private JsonHttp $json;

    public function __construct(ClientInterface $http, string $url, private string $database = 'neo4j')
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("\"$url\" is not a valid Neo4j HTTP URL (e.g. http://neo4j:secret@localhost:7474).");
        }
        $headers = isset($parts['user']) ? ['Authorization' => 'Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''))] : [];
        $this->json = new JsonHttp($http, ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $headers, 'Neo4j');
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>> one array per row, keyed by column
     */
    public function run(string $cypher, array $parameters = []): array
    {
        return $this->runAll([[$cypher, $parameters]])[0];
    }

    /**
     * Runs statements in one transaction: all commit, or none.
     *
     * @param list<array{0: string, 1?: array<string, mixed>}> $statements
     * @return list<list<array<string, mixed>>> rows per statement
     */
    public function runAll(array $statements): array
    {
        $payload = ['statements' => array_map(static fn (array $s): array => ['statement' => $s[0], 'parameters' => ($s[1] ?? []) === [] ? new \stdClass() : $s[1]], $statements)];
        [, $result] = $this->json->request('POST', '/db/' . rawurlencode($this->database) . '/tx/commit', $payload);
        if (!\is_array($result)) {
            throw new NoSqlException('Neo4j returned no result.');
        }
        $errors = \is_array($result['errors'] ?? null) ? $result['errors'] : [];
        if ($errors !== []) {
            $first = \is_array($errors[0] ?? null) ? $errors[0] : [];
            throw new NoSqlException('Neo4j: ' . (\is_string($first['message'] ?? null) ? $first['message'] : 'the statement failed'), 0, \is_string($first['code'] ?? null) ? $first['code'] : '');
        }
        $out = [];
        foreach (\is_array($result['results'] ?? null) ? $result['results'] : [] as $statement) {
            $columns = \is_array($statement) && \is_array($statement['columns'] ?? null) ? array_values($statement['columns']) : [];
            $rows = [];
            foreach (\is_array($statement) && \is_array($statement['data'] ?? null) ? $statement['data'] : [] as $data) {
                $row = \is_array($data) && \is_array($data['row'] ?? null) ? array_values($data['row']) : [];
                $rows[] = array_combine(array_map(static fn (mixed $c): string => \is_scalar($c) ? (string) $c : '', $columns), array_pad($row, \count($columns), null));
            }
            $out[] = $rows;
        }

        return $out;
    }
}
