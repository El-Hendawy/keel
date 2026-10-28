# Non-relational databases

Keel talks to document, search, wide-column, graph and time-series databases without extensions or SDKs:
the drivers speak each database's protocol in PHP, through Keel's HTTP client or the event loop, so they are
traced, protected by a circuit breaker, and non-blocking under `keel run`.

```php
// app.php
->withDocumentStore()

// config.php
'nosql' => [
    'store' => 'mongodb',                   // the DocumentStore: mongodb, dynamodb, elasticsearch, opensearch, couchdb, memory
    'mongodb' => ['uri' => getenv('MONGODB_URI')],
    'dynamodb' => ['region' => 'eu-west-1', 'prefix' => 'shop_'],
    'elasticsearch' => ['url' => getenv('SEARCH_URL')],
    'couchdb' => ['url' => getenv('COUCHDB_URL')],
    'neo4j' => ['url' => getenv('NEO4J_URL')],
    'influxdb' => ['url' => getenv('INFLUX_URL'), 'token' => getenv('INFLUX_TOKEN'), 'org' => 'acme', 'bucket' => 'metrics'],
],
```

| Database | Client | Protocol | DocumentStore | Tested in CI against |
| --- | --- | --- | --- | --- |
| MongoDB (and Atlas, Cosmos DB for MongoDB, DocumentDB) | `MongoClient` | Wire protocol (OP_MSG, BSON), SCRAM-SHA-256, TLS, `mongodb+srv` | `MongoDocumentStore` | MongoDB 7 |
| Amazon DynamoDB | `DynamoDbClient` | JSON API, SigV4 | `DynamoDbStore` | DynamoDB Local |
| Elasticsearch, OpenSearch | `SearchClient` | REST | `SearchDocumentStore` | Elasticsearch 8 |
| Apache CouchDB, Cloudant | `CouchDbClient` | REST, Mango queries | `CouchDbStore` | CouchDB 3.4 |
| Neo4j | `Neo4jClient` | HTTP transactional Cypher | | Requests unit-tested |
| InfluxDB 2 and 3 | `InfluxDbClient` | v2 write API (line protocol), Flux | | Requests unit-tested |
| Redis | `RedisConnection`, `AsyncRedis` | RESP | | Redis 7 (see [cache](cache-and-pagination.md)) |

Cassandra/ScyllaDB (CQL), Couchbase and Firestore have no driver yet; use their PHP extensions or SDKs
alongside Keel.

## DocumentStore

The operations every document database shares, so the same code runs on any of them, and on
`InMemoryDocumentStore` in tests:

```php
public function __construct(private DocumentStore $documents) {}

$this->documents->put('carts', $cartId, ['customer' => 'ana', 'items' => $items]);   // create or replace
$cart = $this->documents->get('carts', $cartId);                                      // with _id, or null
$open = $this->documents->find('carts', ['status' => 'open'], limit: 50);             // equality on top-level fields
$this->documents->delete('carts', $cartId);
```

Each store maps a collection to its own notion: a MongoDB collection, a DynamoDB table (`prefix` +
collection, string partition key `_id`), an index, a CouchDB database. `find()` on DynamoDB is a filtered
Scan: design Query access with keys for hot paths. The production checks refuse the in-memory store.

## MongoDB

```php
$mongo = $connections->mongo();                    // one connection per process
$orders = $mongo->collection('orders');

$orders->insertOne(['customer' => 'ana', 'total' => Decimal128::fromString('19.99'), 'at' => new DateTimeImmutable()]);
$orders->find(['customer' => 'ana', 'total' => ['$gte' => Decimal128::fromString('10')]], ['sort' => ['at' => -1], 'limit' => 20]);
$orders->updateOne(['_id' => $id], ['$set' => ['status' => 'paid']]);
$orders->aggregate([['$match' => ['status' => 'paid']], ['$group' => ['_id' => '$customer', 'n' => ['$sum' => 1]]]]);
$orders->createIndex(['customer' => 1, 'at' => -1]);
$mongo->command('admin', ['ping' => 1]);           // any command
```

- BSON: documents are PHP arrays; `ObjectId`, `Decimal128` (exact, for money), `DateTimeImmutable`,
  `Binary`, `Regex`. An empty PHP array is a document; use `new BsonArray([])` for an empty array.
- Cursors are followed batch by batch (`getMore`); a duplicate key throws `MongoException` with code 11000.
- Writes go to the primary, found with `hello` among the seed hosts; after a failover the client
  reconnects and retries the command once.
- Not supported: multi-document transactions, change streams, compression, client-side field-level
  encryption. Use the official `mongodb/mongodb` library for those; Keel's own `#[Encrypted]` and
  `Encrypter` work with any store.

## DynamoDB

```php
$dynamo = $connections->dynamoDb();
$dynamo->call('PutItem', ['TableName' => 'orders', 'Item' => Marshaler::item($order), 'ConditionExpression' => 'attribute_not_exists(#id)', 'ExpressionAttributeNames' => ['#id' => '_id']]);
$result = $dynamo->call('Query', ['TableName' => 'orders', 'KeyConditionExpression' => 'customer = :c', 'ExpressionAttributeValues' => Marshaler::item([':c' => 'ana'])]);
$orders = array_map(Marshaler::unmarshal(...), $result['Items']);
```

Every operation of the API is available through `call()`. Numbers stay exact: integers come back as `int`,
decimals as strings. Credentials: `nosql.dynamodb.key`/`secret`/`token` or the `AWS_*` environment;
`nosql.dynamodb.endpoint` for DynamoDB Local or LocalStack.

## Elasticsearch and OpenSearch

```php
$search = $connections->search();
$search->index('products', $id, ['name' => 'Keel mug', 'price' => 12.5]);
$search->bulk('products', $documents);
$result = $search->search('products', ['query' => ['match' => ['name' => 'mug']], 'aggs' => [...]]);   // total, hits, aggregations
```

Basic auth in the URL or `nosql.elasticsearch.api_key`. `raw()` reaches any other endpoint.

## CouchDB, Neo4j and InfluxDB

```php
$connections->couchDb()->put('orders', $id, $order);                      // revisions handled for you
$connections->couchDb()->find('orders', ['status' => 'open']);           // Mango

$connections->neo4j()->run('MATCH (a:Account {id: $id})-[:SENT*1..3]->(b) RETURN DISTINCT b.id AS id', ['id' => $id]);

$connections->influxDb()->write('payments', ['psp' => 'adyen'], ['amount' => 19.99, 'count' => 1]);
$connections->influxDb()->query('from(bucket: "metrics") |> range(start: -1h)');
```

## Testing

Bind `DocumentStore` to `InMemoryDocumentStore` in tests (`'nosql' => ['store' => 'memory']`). The
framework's own tests run the DocumentStore contract against every database above in CI, and check the
MongoDB driver's BSON, Decimal128 and SCRAM-SHA-256 against their published test vectors.
