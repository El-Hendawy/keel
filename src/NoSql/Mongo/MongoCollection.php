<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/**
 * One collection: the CRUD commands with MongoDB's own filter and update syntax.
 *
 *     $orders->find(['status' => 'open', 'total' => ['$gte' => 100]], ['sort' => ['createdAt' => -1], 'limit' => 50]);
 *     $orders->updateOne(['_id' => $id], ['$set' => ['status' => 'paid']]);
 */
final readonly class MongoCollection
{
    public function __construct(private MongoClient $client, public string $database, public string $name)
    {
    }

    /**
     * @param array<string, mixed> $document an `_id` (ObjectId) is added when missing
     * @return mixed the `_id`
     */
    public function insertOne(array $document): mixed
    {
        return $this->insertMany([$document])[0];
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @return list<mixed> the `_id`s, in order
     */
    public function insertMany(array $documents): array
    {
        $ids = [];
        foreach ($documents as $i => $document) {
            $documents[$i] = ['_id' => $document['_id'] ?? new ObjectId()] + $document;
            $ids[] = $documents[$i]['_id'];
        }
        $this->client->command($this->database, ['insert' => $this->name, 'documents' => new BsonArray($documents), 'ordered' => true]);

        return $ids;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array{sort?: array<string, int>, projection?: array<string, mixed>, skip?: int} $options
     * @return array<string, mixed>|null
     */
    public function findOne(array $filter = [], array $options = []): ?array
    {
        return $this->find($filter, ['limit' => 1] + $options)[0] ?? null;
    }

    /**
     * Reads every matching document, following the cursor batch by batch.
     *
     * @param array<string, mixed> $filter
     * @param array{sort?: array<string, int>, projection?: array<string, mixed>, skip?: int, limit?: int, batchSize?: int} $options
     * @return list<array<string, mixed>>
     */
    public function find(array $filter = [], array $options = []): array
    {
        $command = ['find' => $this->name, 'filter' => (object) $filter];
        foreach (['sort', 'projection'] as $key) {
            if (isset($options[$key])) {
                $command[$key] = (object) $options[$key];
            }
        }
        foreach (['skip', 'limit', 'batchSize'] as $key) {
            if (isset($options[$key])) {
                $command[$key] = $options[$key];
            }
        }
        if (isset($options['limit']) && $options['limit'] === 1) {
            $command['singleBatch'] = true;
        }

        return $this->drain($this->client->command($this->database, $command));
    }

    /**
     * @param list<array<string, mixed>> $pipeline
     * @return list<array<string, mixed>>
     */
    public function aggregate(array $pipeline): array
    {
        return $this->drain($this->client->command($this->database, ['aggregate' => $this->name, 'pipeline' => new BsonArray($pipeline), 'cursor' => new \stdClass()]));
    }

    /** @param array<string, mixed> $filter */
    public function countDocuments(array $filter = []): int
    {
        $result = $this->aggregate([['$match' => (object) $filter], ['$count' => 'n']]);

        return \is_int($result[0]['n'] ?? null) ? $result[0]['n'] : 0;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update operators such as ['$set' => [...]]
     * @return int documents modified
     */
    public function updateOne(array $filter, array $update, bool $upsert = false): int
    {
        return $this->update($filter, $update, false, $upsert);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    public function updateMany(array $filter, array $update): int
    {
        return $this->update($filter, $update, true, false);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $document the whole new document (no operators)
     */
    public function replaceOne(array $filter, array $document, bool $upsert = false): int
    {
        return $this->update($filter, $document, false, $upsert);
    }

    /** @param array<string, mixed> $filter */
    public function deleteOne(array $filter): int
    {
        return $this->delete($filter, 1);
    }

    /** @param array<string, mixed> $filter */
    public function deleteMany(array $filter): int
    {
        return $this->delete($filter, 0);
    }

    /**
     * @param array<string, int|string> $keys e.g. ['tenantId' => 1, 'createdAt' => -1]
     * @param array<string, mixed> $options e.g. ['unique' => true]
     */
    public function createIndex(array $keys, array $options = []): void
    {
        $name = implode('_', array_map(static fn (string $k, int|string $v): string => "{$k}_$v", array_keys($keys), $keys));
        $this->client->command($this->database, ['createIndexes' => $this->name, 'indexes' => new BsonArray([['key' => (object) $keys, 'name' => $name] + $options])]);
    }

    public function drop(): void
    {
        try {
            $this->client->command($this->database, ['drop' => $this->name]);
        } catch (MongoException $e) {
            if ($e->codeName !== 'NamespaceNotFound') {
                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    private function update(array $filter, array $update, bool $multi, bool $upsert): int
    {
        $reply = $this->client->command($this->database, ['update' => $this->name, 'updates' => new BsonArray([['q' => (object) $filter, 'u' => (object) $update, 'multi' => $multi, 'upsert' => $upsert]])]);
        $upserted = \is_array($reply['upserted'] ?? null) ? \count($reply['upserted']) : 0;

        return (\is_int($reply['nModified'] ?? null) ? $reply['nModified'] : 0) + $upserted;
    }

    /** @param array<string, mixed> $filter */
    private function delete(array $filter, int $limit): int
    {
        $reply = $this->client->command($this->database, ['delete' => $this->name, 'deletes' => new BsonArray([['q' => (object) $filter, 'limit' => $limit]])]);

        return \is_int($reply['n'] ?? null) ? $reply['n'] : 0;
    }

    /**
     * @param array<string, mixed> $reply
     * @return list<array<string, mixed>>
     */
    private function drain(array $reply): array
    {
        $documents = [];
        $cursor = \is_array($reply['cursor'] ?? null) ? $reply['cursor'] : [];
        $batch = $cursor['firstBatch'] ?? [];
        while (true) {
            foreach (\is_array($batch) ? $batch : [] as $document) {
                if (\is_array($document)) {
                    /** @var array<string, mixed> $document */
                    $documents[] = $document;
                }
            }
            $id = $cursor['id'] ?? 0;
            if (!\is_int($id) || $id === 0) {
                return $documents;
            }
            $reply = $this->client->command($this->database, ['getMore' => new Int64($id), 'collection' => $this->name]);
            $cursor = \is_array($reply['cursor'] ?? null) ? $reply['cursor'] : [];
            $batch = $cursor['nextBatch'] ?? [];
        }
    }
}
