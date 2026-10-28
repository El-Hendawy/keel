<?php

declare(strict_types=1);

namespace Keel\Tests\NoSql;

use Keel\Http\Client\CurlHttpClient;
use Keel\NoSql\CouchDb\CouchDbClient;
use Keel\NoSql\CouchDb\CouchDbStore;
use Keel\NoSql\DocumentStore;
use Keel\NoSql\DynamoDb\DynamoDbClient;
use Keel\NoSql\DynamoDb\DynamoDbStore;
use Keel\NoSql\DynamoDb\Marshaler;
use Keel\NoSql\Elasticsearch\SearchClient;
use Keel\NoSql\Elasticsearch\SearchDocumentStore;
use Keel\NoSql\InfluxDb\InfluxDbClient;
use Keel\NoSql\InMemoryDocumentStore;
use Keel\NoSql\Mongo\Binary;
use Keel\NoSql\Mongo\Bson;
use Keel\NoSql\Mongo\BsonArray;
use Keel\NoSql\Mongo\Decimal128;
use Keel\NoSql\Mongo\Int64;
use Keel\NoSql\Mongo\MongoClient;
use Keel\NoSql\Mongo\MongoDocumentStore;
use Keel\NoSql\Mongo\MongoException;
use Keel\NoSql\Mongo\ObjectId;
use Keel\NoSql\Mongo\Scram;
use Keel\NoSql\Neo4j\Neo4jClient;
use Keel\NoSql\NoSqlException;
use Keel\Queue\Transport\SigV4;
use Keel\Support\SystemClock;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The drivers' encodings against published vectors, their requests against a recording client,
 * and the DocumentStore contract against every database whose URL is in the environment
 * (KEEL_TEST_MONGODB_URL, KEEL_TEST_DYNAMODB_ENDPOINT, KEEL_TEST_ELASTICSEARCH_URL,
 * KEEL_TEST_COUCHDB_URL; CI runs them all).
 */
final class NoSqlTest extends TestCase
{
    public function testBsonMatchesTheSpecification(): void
    {
        // bsonspec.org's examples
        self::assertSame("\x16\x00\x00\x00\x02hello\x00\x06\x00\x00\x00world\x00\x00", Bson::encode(['hello' => 'world']));
        self::assertSame(
            "\x31\x00\x00\x00\x04BSON\x00\x26\x00\x00\x00\x020\x00\x08\x00\x00\x00awesome\x00\x011\x00\x33\x33\x33\x33\x33\x33\x14\x40\x102\x00\xc2\x07\x00\x00\x00\x00",
            Bson::encode(['BSON' => ['awesome', 5.05, 1986]]),
        );

        $date = new \DateTimeImmutable('2026-03-01T12:34:56.789Z');
        $id = new ObjectId('65e1c0f0a1b2c3d4e5f60718');
        $document = [
            'string' => 'é', 'int32' => -5, 'int64' => 2 ** 40, 'negative64' => -(2 ** 40), 'double' => 0.1, 'true' => true, 'null' => null,
            'list' => [1, 'two', ['three' => 3]], 'empty' => [], 'emptyList' => new BsonArray([]), 'object' => new \stdClass(),
            'id' => $id, 'date' => $date, 'binary' => new Binary("\x00\xff", 4), 'money' => Decimal128::fromString('-19.99'), 'long' => new Int64(7),
        ];
        $decoded = Bson::decode(Bson::encode($document));

        self::assertSame(['é', -5, 2 ** 40, -(2 ** 40), 0.1, true, null], [$decoded['string'], $decoded['int32'], $decoded['int64'], $decoded['negative64'], $decoded['double'], $decoded['true'], $decoded['null']]);
        self::assertSame([1, 'two', ['three' => 3]], $decoded['list']);
        self::assertSame([[], [], []], [$decoded['empty'], $decoded['emptyList'], $decoded['object']]);
        self::assertSame("\x03", Bson::encode(['e' => []])[4], 'an empty PHP array is a document');
        self::assertSame("\x04", Bson::encode(['e' => new BsonArray([])])[4]);
        self::assertInstanceOf(ObjectId::class, $decoded['id']);
        self::assertSame('65e1c0f0a1b2c3d4e5f60718', (string) $decoded['id']);
        self::assertSame($date->format('Y-m-d\TH:i:s.v'), $decoded['date'] instanceof \DateTimeImmutable ? $decoded['date']->format('Y-m-d\TH:i:s.v') : null);
        self::assertSame(["\x00\xff", 4], $decoded['binary'] instanceof Binary ? [$decoded['binary']->data, $decoded['binary']->subtype] : null);
        self::assertSame('-19.99', (string) $decoded['money']);
        self::assertSame(7, $decoded['long']);
        self::assertSame(24, \strlen((string) new ObjectId()));
    }

    /** @return iterable<array{string, string}> canonical BSON corpus vectors (little-endian bytes as hex) */
    public static function decimals(): iterable
    {
        yield ['0', '00000000000000000000000000004030'];
        yield ['1', '01000000000000000000000000004030'];
        yield ['-1.23', '7B000000000000000000000000003CB0'];
        yield ['0.001', '01000000000000000000000000003A30'];
        yield ['1234567890123456789012345678901234', 'F2AF967ED05C82DE3297FF6FDE3C4030'];
        yield ['9999999999999999999999999999999999', 'FFFFFFFF638E8D37C087ADBE09ED4130'];
    }

    #[DataProvider('decimals')]
    public function testDecimal128(string $decimal, string $hex): void
    {
        self::assertSame($hex, strtoupper(bin2hex(Decimal128::fromString($decimal)->bytes)));
        self::assertSame($decimal, (new Decimal128((string) hex2bin($hex)))->toString());
    }

    public function testScramSha256MatchesRfc7677(): void
    {
        $scram = new Scram('user', 'pencil', 'rOprNGfwEbeRWgbNEkqO');
        self::assertSame('n,,n=user,r=rOprNGfwEbeRWgbNEkqO', $scram->first());
        $final = $scram->final('r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096');
        self::assertSame('c=biws,r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,p=dHzbZapWIk4jUhN+Ute9ytag9zjfMHgsqmmiz7AndVQ=', $final);
        $scram->verify('v=6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4=');

        $this->expectException(MongoException::class);
        $scram->verify('v=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function testMongoConnectionStrings(): void
    {
        $client = new MongoClient('mongodb://app:p%40ss@db1:27018,db2/shop?authSource=admin');
        self::assertSame('shop', $client->database());
        self::assertSame('test', (new MongoClient('mongodb://localhost'))->database());

        $this->expectException(\InvalidArgumentException::class);
        new MongoClient('postgres://localhost');
    }

    public function testDynamoDbRequestsAreSignedAndMarshaled(): void
    {
        $http = new RecordingClient([new Response(200, ['Content-Type' => 'application/x-amz-json-1.0'], '{"Item":{"_id":{"S":"o1"},"total":{"N":"12.50"},"count":{"N":"3"},"tags":{"L":[{"S":"a"}]},"meta":{"M":{"vip":{"BOOL":true}}},"gone":{"NULL":true}}}')]);
        $store = new DynamoDbStore(new DynamoDbClient($http, new SigV4('AKID', 'secret', 'eu-west-1', 'dynamodb'), new SystemClock(), 'eu-west-1'), 'shop_');

        self::assertSame(['_id' => 'o1', 'total' => '12.50', 'count' => 3, 'tags' => ['a'], 'meta' => ['vip' => true], 'gone' => null], $store->get('orders', 'o1'));
        $request = $http->requests[0];
        self::assertSame('https://dynamodb.eu-west-1.amazonaws.com/', (string) $request->getUri());
        self::assertSame('DynamoDB_20120810.GetItem', $request->getHeaderLine('X-Amz-Target'));
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $request->getHeaderLine('Authorization'));
        self::assertSame(['TableName' => 'shop_orders', 'Key' => ['_id' => ['S' => 'o1']], 'ConsistentRead' => true], json_decode((string) $request->getBody(), true));

        self::assertSame(['M' => ['a' => ['N' => '1'], 'b' => ['L' => [['S' => 'x'], ['NULL' => true]]]]], json_decode((string) json_encode(Marshaler::value(['a' => 1, 'b' => ['x', null]])), true));
    }

    public function testHttpDriversShapeTheirRequests(): void
    {
        $http = new RecordingClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"hits":{"total":{"value":1},"hits":[{"_id":"p1","_score":1.2,"_source":{"name":"Keel mug"}}]}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"results":[{"columns":["id","n"],"data":[{"row":["acc_2",3]}]}],"errors":[]}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"results":[],"errors":[{"code":"Neo.ClientError.Statement.SyntaxError","message":"Invalid input"}]}'),
            new Response(204),
            new Response(200, ['Content-Type' => 'text/csv'], "#datatype,string,long,double\n,result,table,_value,psp\n,_result,0,19.99,adyen\n,_result,0,5.5,mollie\n"),
        ]);

        $search = new SearchClient($http, 'https://elastic:s%3Acret@search.local:9200');
        self::assertSame(['total' => 1, 'hits' => [['_id' => 'p1', '_score' => 1.2, 'name' => 'Keel mug']], 'aggregations' => []], $search->search('products', ['query' => ['match' => ['name' => 'mug']]]));
        self::assertSame('Basic ' . base64_encode('elastic:s:cret'), $http->requests[0]->getHeaderLine('Authorization'));

        $neo4j = new Neo4jClient($http, 'http://neo4j:secret@graph:7474');
        self::assertSame([['id' => 'acc_2', 'n' => 3]], $neo4j->run('MATCH (a {id: $id})-[:SENT]->(b) RETURN b.id AS id, 3 AS n', ['id' => 'acc_1']));
        self::assertSame('http://graph:7474/db/neo4j/tx/commit', (string) $http->requests[1]->getUri());
        try {
            $neo4j->run('MATCH (');
            self::fail('A Cypher error must throw.');
        } catch (NoSqlException $e) {
            self::assertSame('Neo.ClientError.Statement.SyntaxError', $e->type);
        }

        $influx = new InfluxDbClient($http, 'http://influx:8086', 'tok', 'acme', 'payments');
        $influx->write('pay ments', ['psp' => 'adyen', 'cur rency' => 'EUR'], ['amount' => 19.99, 'count' => 1, 'ok' => true, 'note' => 'say "hi"'], new \DateTimeImmutable('@1767225600.5'));
        self::assertSame('pay\ ments,cur\ rency=EUR,psp=adyen amount=19.99,count=1i,ok=true,note="say \"hi\"" 1767225600500000000', (string) $http->requests[3]->getBody());
        self::assertSame('Token tok', $http->requests[3]->getHeaderLine('Authorization'));
        self::assertSame([['_value' => '19.99', 'psp' => 'adyen'], ['_value' => '5.5', 'psp' => 'mollie']], $influx->query('from(bucket: "payments")'));
    }

    /** @return iterable<string, array{\Closure(): ?DocumentStore}> */
    public static function stores(): iterable
    {
        $http = static fn (): ClientInterface => new CurlHttpClient();
        $env = static fn (string $name): ?string => \is_string(getenv($name)) && getenv($name) !== '' ? (string) getenv($name) : null;
        yield 'memory' => [static fn (): DocumentStore => new InMemoryDocumentStore()];
        yield 'mongodb' => [static fn (): ?DocumentStore => ($url = $env('KEEL_TEST_MONGODB_URL')) === null ? null : new MongoDocumentStore(new MongoClient($url))];
        yield 'dynamodb' => [static function () use ($env, $http): ?DocumentStore {
            $endpoint = $env('KEEL_TEST_DYNAMODB_ENDPOINT');
            if ($endpoint === null) {
                return null;
            }
            $store = new DynamoDbStore(new DynamoDbClient($http(), new SigV4('test', 'test', 'us-east-1', 'dynamodb'), new SystemClock(), 'us-east-1', $endpoint), 'keel_' . bin2hex(random_bytes(3)) . '_');
            $store->createCollection('contract');

            return $store;
        }];
        yield 'elasticsearch' => [static fn (): ?DocumentStore => ($url = $env('KEEL_TEST_ELASTICSEARCH_URL')) === null ? null : new SearchDocumentStore(new SearchClient($http(), $url), 'keel_' . bin2hex(random_bytes(3)) . '_')];
        yield 'couchdb' => [static function () use ($env, $http): ?DocumentStore {
            $url = $env('KEEL_TEST_COUCHDB_URL');
            if ($url === null) {
                return null;
            }
            $client = new CouchDbClient($http(), $url);
            $prefix = 'keel_' . bin2hex(random_bytes(3)) . '_';
            $client->createDatabase($prefix . 'contract');

            return new CouchDbStore($client, $prefix);
        }];
    }

    /** @param \Closure(): ?DocumentStore $make */
    #[DataProvider('stores')]
    public function testDocumentStoreContract(\Closure $make): void
    {
        $store = $make();
        if ($store === null) {
            self::markTestSkipped('Set the database URL in the environment to run this store.');
        }
        $collection = 'contract';
        if ($store instanceof MongoDocumentStore) {
            (new MongoClient((string) getenv('KEEL_TEST_MONGODB_URL')))->collection($collection)->drop();
        }

        self::assertNull($store->get($collection, 'missing'));
        $store->put($collection, 'a', ['status' => 'open', 'total' => 10, 'tags' => ['x', 'y'], 'meta' => ['vip' => true]]);
        $store->put($collection, 'b', ['status' => 'open', 'total' => 20]);
        $store->put($collection, 'c', ['status' => 'paid', 'total' => 30]);
        $store->put($collection, 'a', ['status' => 'open', 'total' => 11, 'tags' => ['x', 'y'], 'meta' => ['vip' => true]]);

        self::assertSame(['_id' => 'a', 'status' => 'open', 'total' => 11, 'tags' => ['x', 'y'], 'meta' => ['vip' => true]], self::sorted($store->get($collection, 'a')));
        $open = $store->find($collection, ['status' => 'open']);
        self::assertSame(['a', 'b'], self::ids($open));
        self::assertSame(['c'], self::ids($store->find($collection, ['status' => 'paid', 'total' => 30])));
        self::assertCount(1, $store->find($collection, [], limit: 1));
        self::assertTrue($store->delete($collection, 'b'));
        self::assertFalse($store->delete($collection, 'b'));
        self::assertSame(['a'], self::ids($store->find($collection, ['status' => 'open'])));
    }

    public function testMongoClientAgainstAServer(): void
    {
        $url = getenv('KEEL_TEST_MONGODB_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('Set KEEL_TEST_MONGODB_URL to run against MongoDB.');
        }
        $orders = (new MongoClient($url))->collection('orders');
        $orders->drop();
        $orders->createIndex(['customer' => 1, 'n' => -1]);
        $ids = $orders->insertMany(array_map(static fn (int $n): array => ['customer' => $n % 2 === 0 ? 'ana' : 'ben', 'n' => $n, 'total' => Decimal128::fromString("$n.50")], range(1, 250)));

        self::assertCount(250, $ids);
        self::assertSame(125, $orders->countDocuments(['customer' => 'ana']));
        $page = $orders->find(['customer' => 'ana'], ['sort' => ['n' => -1], 'batchSize' => 50]);
        self::assertCount(125, $page, 'getMore follows the cursor');
        self::assertSame([250, '250.50'], [$page[0]['n'], (string) $page[0]['total']]);
        self::assertSame(125, $orders->updateMany(['customer' => 'ben'], ['$inc' => ['n' => 1000]]));
        self::assertSame(1001, $orders->findOne(['customer' => 'ben'], ['sort' => ['n' => 1]])['n'] ?? null);
        self::assertSame([['_id' => 'ana', 'sum' => 125 * 126]], $orders->aggregate([['$match' => ['customer' => 'ana']], ['$group' => ['_id' => '$customer', 'sum' => ['$sum' => '$n']]]]));

        $orders->createIndex(['email' => 1], ['unique' => true, 'sparse' => true]);
        $orders->insertOne(['email' => 'a@example.com']);
        try {
            $orders->insertOne(['email' => 'a@example.com']);
            self::fail('A duplicate key must throw.');
        } catch (MongoException $e) {
            self::assertSame(11000, $e->getCode());
        }
        self::assertSame(250, $orders->deleteMany(['n' => ['$exists' => true]]));
    }

    /** @param array<string, mixed>|null $document */
    private static function sorted(?array $document): ?array
    {
        if ($document !== null) {
            ksort($document);
            $document = ['_id' => $document['_id']] + $document;
            $expectedOrder = ['_id', 'status', 'total', 'tags', 'meta'];
            $document = array_merge(array_intersect_key(array_flip($expectedOrder), $document), $document);
        }

        return $document;
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @return list<string>
     */
    private static function ids(array $documents): array
    {
        $ids = array_map(static fn (array $d): string => \is_string($d['_id'] ?? null) ? $d['_id'] : '', $documents);
        sort($ids);

        return $ids;
    }
}

/** Returns canned responses in order and keeps the requests. */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(500);
    }
}
