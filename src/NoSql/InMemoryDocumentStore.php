<?php

declare(strict_types=1);

namespace Keel\NoSql;

use Keel\Container\Attribute\Singleton;

/** A DocumentStore in process memory, for tests and development. */
#[Singleton]
final class InMemoryDocumentStore implements DocumentStore
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $collections = [];

    public function get(string $collection, string $id): ?array
    {
        return $this->collections[$collection][$id] ?? null;
    }

    public function put(string $collection, string $id, array $document): void
    {
        $this->collections[$collection][$id] = ['_id' => $id] + $document;
    }

    public function delete(string $collection, string $id): bool
    {
        $existed = isset($this->collections[$collection][$id]);
        unset($this->collections[$collection][$id]);

        return $existed;
    }

    public function find(string $collection, array $equals = [], int $limit = 100): array
    {
        $found = [];
        foreach ($this->collections[$collection] ?? [] as $document) {
            foreach ($equals as $field => $value) {
                if (!\array_key_exists($field, $document) || $document[$field] !== $value) {
                    continue 2;
                }
            }
            $found[] = $document;
            if (\count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }
}
