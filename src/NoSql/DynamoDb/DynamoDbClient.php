<?php

declare(strict_types=1);

namespace Keel\NoSql\DynamoDb;

use Keel\NoSql\JsonHttp;
use Keel\Queue\Transport\SigV4;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Amazon DynamoDB through its JSON API, signed with SigV4: no AWS SDK needed.
 *
 *     $dynamo->call('PutItem', ['TableName' => 'orders', 'Item' => Marshaler::item($order)]);
 *     $dynamo->call('Query', ['TableName' => 'orders', 'KeyConditionExpression' => 'customer = :c',
 *         'ExpressionAttributeValues' => Marshaler::item([':c' => 'ana'])]);
 *
 * `endpoint` points at DynamoDB Local or LocalStack in development.
 */
final readonly class DynamoDbClient
{
    private JsonHttp $json;

    public function __construct(ClientInterface $http, private SigV4 $signer, private ClockInterface $clock, string $region, ?string $endpoint = null)
    {
        $this->json = new JsonHttp($http, $endpoint ?? "https://dynamodb.$region.amazonaws.com", [], 'DynamoDB');
    }

    /**
     * Runs one API operation (PutItem, GetItem, Query, TransactWriteItems, ...).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function call(string $operation, array $payload): array
    {
        [, $result] = $this->json->request(
            'POST',
            '/',
            $payload === [] ? new \stdClass() : $payload,
            ['Content-Type' => 'application/x-amz-json-1.0', 'X-Amz-Target' => 'DynamoDB_20120810.' . $operation],
            sign: fn (RequestInterface $r): RequestInterface => $this->signer->sign($r, $this->clock->now()),
        );

        /** @var array<string, mixed> */
        return \is_array($result) ? $result : [];
    }
}
