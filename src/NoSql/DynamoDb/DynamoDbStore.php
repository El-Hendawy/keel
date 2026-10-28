<?php

declare(strict_types=1);

namespace Keel\NoSql\DynamoDb;

use Keel\NoSql\DocumentStore;

/**
 * DocumentStore on DynamoDB: one table per collection (`$prefix . $collection`), each with a
 * string partition key named `_id`. find() is a filtered Scan: fine for small tables and
 * back-office tools; design a Query with a key for hot paths.
 */
final readonly class DynamoDbStore implements DocumentStore
{
    public function __construct(private DynamoDbClient $client, private string $prefix = '')
    {
    }

    public function get(string $collection, string $id): ?array
    {
        $result = $this->client->call('GetItem', ['TableName' => $this->prefix . $collection, 'Key' => ['_id' => ['S' => $id]], 'ConsistentRead' => true]);

        return \is_array($result['Item'] ?? null) ? Marshaler::unmarshal($result['Item']) : null;
    }

    public function put(string $collection, string $id, array $document): void
    {
        $this->client->call('PutItem', ['TableName' => $this->prefix . $collection, 'Item' => Marshaler::item(['_id' => $id] + $document)]);
    }

    public function delete(string $collection, string $id): bool
    {
        $result = $this->client->call('DeleteItem', ['TableName' => $this->prefix . $collection, 'Key' => ['_id' => ['S' => $id]], 'ReturnValues' => 'ALL_OLD']);

        return isset($result['Attributes']);
    }

    public function find(string $collection, array $equals = [], int $limit = 100): array
    {
        $payload = ['TableName' => $this->prefix . $collection];
        if ($equals !== []) {
            $conditions = [];
            $names = [];
            $values = [];
            $i = 0;
            foreach ($equals as $field => $value) {
                $conditions[] = "#f$i = :v$i";
                $names["#f$i"] = $field;
                $values[":v$i"] = Marshaler::value($value);
                $i++;
            }
            $payload += ['FilterExpression' => implode(' AND ', $conditions), 'ExpressionAttributeNames' => $names, 'ExpressionAttributeValues' => $values];
        }
        $found = [];
        do {
            $result = $this->client->call('Scan', $payload);
            foreach (\is_array($result['Items'] ?? null) ? $result['Items'] : [] as $item) {
                if (\is_array($item)) {
                    $found[] = Marshaler::unmarshal($item);
                    if (\count($found) >= $limit) {
                        return $found;
                    }
                }
            }
            $payload['ExclusiveStartKey'] = $result['LastEvaluatedKey'] ?? null;
        } while ($payload['ExclusiveStartKey'] !== null);

        return $found;
    }

    /** Creates the table for a collection (on-demand capacity), for setup scripts and tests. */
    public function createCollection(string $collection): void
    {
        $this->client->call('CreateTable', [
            'TableName' => $this->prefix . $collection,
            'AttributeDefinitions' => [['AttributeName' => '_id', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => '_id', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);
    }
}
