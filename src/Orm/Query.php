<?php

declare(strict_types=1);

namespace Keel\Orm;

use Keel\Database\Database;
use Keel\Http\Exception\NotFound;
use Keel\Pagination\Cursors;
use Keel\Pagination\PageInfo;
use Keel\Orm\Dialect\Dialect;
use Keel\Tenancy\TenantContext;

/**
 * A query for one entity type, created by Orm::query(). Tenant filtering and soft deletes
 * apply automatically; relations are loaded only when listed in with().
 *
 *     $orders = $orm->query(Order::class)
 *         ->where('status', OrderStatus::Placed)
 *         ->whereHas('lines', fn (Criteria $l) => $l->where('sku', 'TEA'))
 *         ->with('lines', 'customer')
 *         ->orderBy('createdAt', 'desc')
 *         ->limit(20)
 *         ->get();
 *
 * @template T of object
 * @phpstan-import-type Meta from EntityMap
 */
final class Query extends Criteria
{
    /** @var list<array{0: string, 1: string}> [property, ASC|DESC] */
    private array $order = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var list<string> */
    private array $with = [];

    private bool $withTrashed = false;

    private bool $onlyTrashed = false;

    private bool $acrossTenants = false;

    private bool $lock = false;

    /**
     * @param class-string<T> $class
     * @param Meta $meta
     */
    public function __construct(
        public readonly string $class,
        private readonly Orm $orm,
        private readonly Database $db,
        private readonly Cursors $cursors,
        EntityMap $map,
        array $meta,
        Dialect $dialect,
        TenantContext $tenant,
    ) {
        parent::__construct($map, $meta, $dialect, $tenant, $dialect->quote($meta['table']));
    }

    /** @return $this */
    public function orderBy(string $property, string $direction = 'asc'): self
    {
        EntityMap::column($this->meta, $property);
        $this->order[] = [$property, strtolower($direction) === 'desc' ? 'DESC' : 'ASC'];

        return $this;
    }

    /** @return $this */
    public function latest(string $property = 'createdAt'): self
    {
        return $this->orderBy($property, 'desc');
    }

    /** @return $this */
    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    /** @return $this */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Eager-loads relations, nested with dots: with('lines.product', 'customer').
     * Each level is one query per relation, whatever the number of rows.
     *
     * @return $this
     */
    public function with(string ...$relations): self
    {
        array_push($this->with, ...$relations);

        return $this;
    }

    /** @return $this */
    public function withTrashed(): self
    {
        $this->withTrashed = true;

        return $this;
    }

    /** @return $this */
    public function onlyTrashed(): self
    {
        $this->onlyTrashed = true;

        return $this;
    }

    /**
     * Unsafe by name: drops the automatic tenant filter for this query.
     *
     * @return $this
     */
    public function unsafeAcrossTenants(): self
    {
        $this->acrossTenants = true;

        return $this;
    }

    /** @return $this */
    public function lockForUpdate(): self
    {
        $this->lock = true;

        return $this;
    }

    /** @return list<T> */
    public function get(): array
    {
        [$sql, $params] = $this->select($this->alias . '.*', true);
        $sql = $this->dialect->paginate($sql, $this->limit, $this->offset) . ($this->lock ? $this->dialect->forUpdate() : '');
        $entities = $this->orm->materialize($this->meta['class'], $this->guarded(fn (): array => $this->db->selectBuilt($sql, $params, $this->lock)));
        if ($this->with !== [] && $entities !== []) {
            $this->orm->load($entities, ...$this->with);
        }

        /** @var list<T> */
        return $entities;
    }

    /** @return T|null */
    public function first(): ?object
    {
        $limit = $this->limit;
        $this->limit = 1;
        $result = $this->get()[0] ?? null;
        $this->limit = $limit;

        return $result;
    }

    /** @return T */
    public function firstOrFail(): object
    {
        return $this->first() ?? throw new NotFound((new \ReflectionClass($this->meta['class']))->getShortName() . ' not found.');
    }

    public function count(): int
    {
        return (int) $this->aggregate('COUNT(*)');
    }

    public function exists(): bool
    {
        [$sql, $params] = $this->select('1', false);

        return $this->guarded(fn (): array => $this->db->selectBuilt($this->dialect->paginate($sql, 1, null), $params)) !== [];
    }

    public function sum(string $property): float
    {
        return (float) $this->aggregate('SUM(' . $this->col($property) . ')');
    }

    public function avg(string $property): ?float
    {
        $value = $this->aggregate('AVG(' . $this->col($property) . ')');

        return $value === null ? null : (float) $value;
    }

    public function max(string $property): string|int|float|null
    {
        return $this->aggregate('MAX(' . $this->col($property) . ')');
    }

    public function min(string $property): string|int|float|null
    {
        return $this->aggregate('MIN(' . $this->col($property) . ')');
    }

    /** @return list<scalar|null> one column's values */
    public function pluck(string $property): array
    {
        [$sql, $params] = $this->select($this->col($property) . ' AS v', true);
        $sql = $this->dialect->paginate($sql, $this->limit, $this->offset);

        $values = [];
        foreach ($this->guarded(fn (): array => $this->db->selectBuilt($sql, $params)) as $row) {
            $value = $row->toArray()['v'] ?? null;
            $values[] = \is_scalar($value) ? $value : null;
        }

        return $values;
    }

    /**
     * Keyset pagination over the order (plus the id as tie-breaker), with signed cursors:
     * deep pages cost the same as the first, and rows never repeat or go missing.
     *
     * @return Page<T>
     */
    public function cursorPaginate(int $limit, ?string $cursor = null): Page
    {
        $order = $this->order === [] ? [[$this->meta['id'], 'ASC']] : $this->order;
        if (!\in_array($this->meta['id'], array_column($order, 0), true)) {
            $order[] = [$this->meta['id'], $order[\count($order) - 1][1]];
        }
        $purpose = 'orm:' . $this->meta['class'] . ':' . implode(',', array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], $order));
        $query = clone $this;
        $query->order = $order;
        if ($cursor !== null) {
            $position = $this->cursors->decode($cursor, $purpose);
            $or = [];
            $params = [];
            foreach ($order as $i => [$property, $direction]) {
                $and = [];
                foreach (\array_slice($order, 0, $i) as [$previous]) {
                    $and[] = $this->col($previous) . ' = ?';
                    $params[] = $position[$previous] ?? null;
                }
                $and[] = $this->col($property) . ($direction === 'ASC' ? ' > ?' : ' < ?');
                $params[] = $position[$property] ?? null;
                $or[] = '(' . implode(' AND ', $and) . ')';
            }
            $query->parts[] = ['AND', '(' . implode(' OR ', $or) . ')', $params];
        }
        $items = $query->limit($limit + 1)->get();
        $hasMore = \count($items) > $limit;
        $items = \array_slice($items, 0, $limit);
        $next = null;
        $last = end($items);
        if ($hasMore && $last !== false) {
            $values = $this->map->extract($last, $this->dialect);
            $position = [];
            foreach ($order as [$property]) {
                $position[$property] = $values[$this->meta['columns'][$property]['column']] ?? null;
            }
            $next = $this->cursors->encode($position, $purpose);
        }

        return new Page($items, new PageInfo($next, $hasMore));
    }

    /**
     * Streams every matching row in batches by id, with flat memory: entities are not kept
     * in the identity map. Ordering is by id.
     *
     * @return \Generator<int, T>
     */
    public function lazy(int $batch = 500): \Generator
    {
        $after = null;
        do {
            $query = clone $this;
            $query->order = [[$this->meta['id'], 'ASC']];
            if ($after !== null) {
                $query->parts[] = ['AND', $this->col($this->meta['id']) . ' > ?', [$after]];
            }
            [$sql, $params] = $query->select($this->alias . '.*', true);
            $rows = $this->guarded(fn (): array => $this->db->selectBuilt($this->dialect->paginate($sql, $batch, null), $params));
            foreach ($rows as $row) {
                $data = $row->toArray();
                $after = $data[$this->meta['idColumn']] ?? null;
                /** @var T $entity */
                $entity = $this->map->hydrate($this->meta['class'], $data);
                yield $entity;
            }
        } while (\count($rows) === $batch && \is_scalar($after));
    }

    /**
     * Bulk update without loading entities (no versions, no identity map). Tenant and
     * soft-delete filters still apply.
     *
     * @param array<string, mixed> $values property => value
     */
    public function update(array $values): int
    {
        $set = [];
        $params = [];
        foreach ($values as $property => $value) {
            $column = EntityMap::column($this->meta, $property);
            $set[] = $this->dialect->quote($column['column']) . ' = ?';
            $params[] = EntityMap::toDb($column, $value, $this->dialect);
        }
        if ($this->meta['updatedAt'] !== null && !isset($values[$this->meta['updatedAt']])) {
            $set[] = $this->dialect->quote($this->meta['columns'][$this->meta['updatedAt']]['column']) . ' = ?';
            $params[] = Values::toDateTime($this->orm->now());
        }
        [$where, $whereParams] = $this->where_();

        return $this->guarded(fn (): int => $this->db->executeBuilt('UPDATE ' . $this->alias . ' SET ' . implode(', ', $set) . $where, [...$params, ...$whereParams]));
    }

    /** Bulk delete; soft-deletes when the entity has #[SoftDelete]. */
    public function delete(): int
    {
        if ($this->meta['softDelete'] !== null) {
            return $this->update([$this->meta['softDelete'] => $this->orm->now()]);
        }
        [$where, $params] = $this->where_();

        return $this->guarded(fn (): int => $this->db->executeBuilt('DELETE FROM ' . $this->alias . $where, $params));
    }

    /** @return array{0: string, 1: list<scalar|null>} */
    private function select(string $columns, bool $ordered): array
    {
        [$where, $params] = $this->where_();
        $sql = "SELECT $columns FROM {$this->alias}$where";
        if ($ordered && $this->order !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(fn (array $o): string => $this->col($o[0]) . ' ' . $o[1], $this->order));
        }

        return [$sql, $params];
    }

    /** @return array{0: string, 1: list<scalar|null>} */
    private function where_(): array
    {
        [$scope, $scopeParams] = $this->scope($this->withTrashed, $this->onlyTrashed, $this->acrossTenants);
        [$sql, $params] = $this->compile();
        $all = [...$scope, ...($sql === '' ? [] : ["($sql)"])];

        return [$all === [] ? '' : ' WHERE ' . implode(' AND ', $all), [...$scopeParams, ...$params]];
    }

    /**
     * Runs a statement with the tenant guard lifted when this query is unsafeAcrossTenants().
     *
     * @template R
     * @param \Closure(): R $statement
     * @return R
     */
    private function guarded(\Closure $statement): mixed
    {
        return $this->acrossTenants ? $this->tenant->acrossTenants($statement) : $statement();
    }

    private function aggregate(string $expression): string|int|float|null
    {
        [$sql, $params] = $this->select("$expression AS v", false);
        $value = ($this->guarded(fn (): array => $this->db->selectBuilt($sql, $params))[0] ?? null)?->toArray()['v'] ?? null;

        return \is_string($value) || \is_int($value) || \is_float($value) ? $value : null;
    }
}
