<?php

declare(strict_types=1);

namespace Keel\NoSql;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\NoSql\CouchDb\CouchDbClient;
use Keel\NoSql\CouchDb\CouchDbStore;
use Keel\NoSql\DynamoDb\DynamoDbClient;
use Keel\NoSql\DynamoDb\DynamoDbStore;
use Keel\NoSql\Elasticsearch\SearchClient;
use Keel\NoSql\Elasticsearch\SearchDocumentStore;
use Keel\NoSql\InfluxDb\InfluxDbClient;
use Keel\NoSql\Mongo\MongoClient;
use Keel\NoSql\Mongo\MongoDocumentStore;
use Keel\NoSql\Neo4j\Neo4jClient;
use Keel\Queue\Transport\SigV4;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;

/**
 * The configured non-relational databases, connected on first use and kept for the life of the
 * worker (one MongoDB connection per process, HTTP keep-alive for the others).
 *
 *     'nosql' => [
 *         'store' => 'mongodb',                       // what DocumentStore uses: mongodb, dynamodb, elasticsearch, couchdb, memory
 *         'mongodb' => ['uri' => getenv('MONGODB_URI')],
 *         'dynamodb' => ['region' => 'eu-west-1', 'endpoint' => null, 'prefix' => 'shop_'],
 *         'elasticsearch' => ['url' => 'https://elastic:secret@search:9200', 'api_key' => null],
 *         'couchdb' => ['url' => 'http://admin:secret@couchdb:5984'],
 *         'neo4j' => ['url' => 'http://neo4j:secret@neo4j:7474', 'database' => 'neo4j'],
 *         'influxdb' => ['url' => 'http://influxdb:8086', 'token' => '...', 'org' => 'acme', 'bucket' => 'metrics'],
 *     ],
 *
 * DynamoDB credentials come from `nosql.dynamodb.key`/`secret`/`token` or the AWS_* environment.
 */
#[Singleton]
final class NoSqlConnections
{
    private ?MongoClient $mongo = null;

    private ?DocumentStore $store = null;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly ClockInterface $clock,
        #[Config('nosql.store')] private readonly string $storeName = 'memory',
        #[Config('nosql.mongodb.uri')] private readonly string $mongoUri = '',
        #[Config('nosql.mongodb.database')] private readonly string $mongoDatabase = '',
        #[Config('nosql.dynamodb.region')] private readonly string $dynamoRegion = '',
        #[Config('nosql.dynamodb.endpoint')] private readonly string $dynamoEndpoint = '',
        #[Config('nosql.dynamodb.prefix')] private readonly string $dynamoPrefix = '',
        #[Config('nosql.dynamodb.key')] private readonly string $dynamoKey = '',
        #[Config('nosql.dynamodb.secret')] private readonly string $dynamoSecret = '',
        #[Config('nosql.dynamodb.token')] private readonly string $dynamoToken = '',
        #[Config('nosql.elasticsearch.url')] private readonly string $searchUrl = '',
        #[Config('nosql.elasticsearch.api_key')] private readonly string $searchApiKey = '',
        #[Config('nosql.elasticsearch.prefix')] private readonly string $searchPrefix = '',
        #[Config('nosql.couchdb.url')] private readonly string $couchUrl = '',
        #[Config('nosql.couchdb.prefix')] private readonly string $couchPrefix = '',
        #[Config('nosql.neo4j.url')] private readonly string $neo4jUrl = '',
        #[Config('nosql.neo4j.database')] private readonly string $neo4jDatabase = 'neo4j',
        #[Config('nosql.influxdb.url')] private readonly string $influxUrl = '',
        #[Config('nosql.influxdb.token')] private readonly string $influxToken = '',
        #[Config('nosql.influxdb.org')] private readonly string $influxOrg = '',
        #[Config('nosql.influxdb.bucket')] private readonly string $influxBucket = '',
    ) {
    }

    public function mongo(): MongoClient
    {
        return $this->mongo ??= new MongoClient($this->required($this->mongoUri, 'nosql.mongodb.uri'));
    }

    public function dynamoDb(): DynamoDbClient
    {
        $region = $this->dynamoRegion !== '' ? $this->dynamoRegion : (getenv('AWS_REGION') ?: 'us-east-1');
        $signer = new SigV4(
            $this->dynamoKey !== '' ? $this->dynamoKey : (string) getenv('AWS_ACCESS_KEY_ID'),
            $this->dynamoSecret !== '' ? $this->dynamoSecret : (string) getenv('AWS_SECRET_ACCESS_KEY'),
            $region,
            'dynamodb',
            $this->dynamoToken !== '' ? $this->dynamoToken : (string) getenv('AWS_SESSION_TOKEN'),
        );

        return new DynamoDbClient($this->http, $signer, $this->clock, $region, $this->dynamoEndpoint === '' ? null : $this->dynamoEndpoint);
    }

    public function search(): SearchClient
    {
        return new SearchClient($this->http, $this->required($this->searchUrl, 'nosql.elasticsearch.url'), $this->searchApiKey === '' ? null : $this->searchApiKey);
    }

    public function couchDb(): CouchDbClient
    {
        return new CouchDbClient($this->http, $this->required($this->couchUrl, 'nosql.couchdb.url'));
    }

    public function neo4j(): Neo4jClient
    {
        return new Neo4jClient($this->http, $this->required($this->neo4jUrl, 'nosql.neo4j.url'), $this->neo4jDatabase);
    }

    public function influxDb(): InfluxDbClient
    {
        return new InfluxDbClient($this->http, $this->required($this->influxUrl, 'nosql.influxdb.url'), $this->influxToken, $this->influxOrg, $this->influxBucket);
    }

    /** The DocumentStore named by `nosql.store`. */
    public function store(): DocumentStore
    {
        return $this->store ??= match ($this->storeName) {
            'memory' => new InMemoryDocumentStore(),
            'mongodb' => new MongoDocumentStore($this->mongo(), $this->mongoDatabase === '' ? null : $this->mongoDatabase),
            'dynamodb' => new DynamoDbStore($this->dynamoDb(), $this->dynamoPrefix),
            'elasticsearch', 'opensearch' => new SearchDocumentStore($this->search(), $this->searchPrefix),
            'couchdb' => new CouchDbStore($this->couchDb(), $this->couchPrefix),
            default => throw new \InvalidArgumentException("nosql.store is \"{$this->storeName}\": use mongodb, dynamodb, elasticsearch, opensearch, couchdb or memory."),
        };
    }

    public function storeName(): string
    {
        return $this->storeName;
    }

    private function required(string $value, string $key): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException("$key is not configured.");
        }

        return $value;
    }
}
