<?php

declare(strict_types=1);

namespace Keel\Orm;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Database\Database;
use Keel\Database\Row;
use Keel\Http\Exception\NotFound;
use Keel\Orm\Dialect\Dialect;
use Keel\Orm\Dialect\Dialects;
use Keel\Pagination\Cursors;
use Keel\Support\IdGenerator;
use Keel\Tenancy\TenantContext;
use Keel\Tenancy\TenantViolation;
use Psr\Clock\ClockInterface;

/**
 * Keel's data mapper: plain entity classes, compiled mappings, and a unit of work per scope.
 *
 * - **Identity map:** within a request, a row is one object; save() writes only the
 *   columns that changed.
 * - **No lazy loading:** relations load with with()/load() in one query per relation, so
 *   N+1 queries cannot happen silently.
 * - **Tenancy:** entities with #[TenantKey] are filtered and stamped with the current tenant.
 * - **Safety:** optimistic locking (#[Version]), soft deletes, timestamps, bound values,
 *   property names checked against compiled metadata.
 * - **Every database:** SQLite, PostgreSQL, MySQL/MariaDB, SQL Server, Oracle, and any other
 *   PDO driver through standard SQL.
 *
 * @phpstan-import-type Meta from EntityMap
 */
#[Scoped]
final class Orm
{
    /** @var array<string, array<string, object>> class => id => entity */
    private array $identity = [];

    /** @var array<string, array<string, array<string, scalar|null>>> class => id => column values as last read or written */
    private array $snapshots = [];

    private ?Dialect $dialect = null;

    public function __construct(
        private readonly Database $db,
        private readonly EntityMap $map,
        private readonly TenantContext $tenant,
        private readonly IdGenerator $ids,
        private readonly ClockInterface $clock,
        private readonly Cursors $cursors,
        #[Config('database.dialect')] private readonly string $dialectOverride = '',
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return Query<T>
     */
    public function query(string $class): Query
    {
        return new Query($class, $this, $this->db, $this->cursors, $this->map, $this->map->meta($class), $this->dialect(), $this->tenant);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function find(string $class, string|int $id): ?object
    {
        $known = $this->identity[$class][(string) $id] ?? null;
        if ($known instanceof $class) {
            return $known;
        }

        return $this->query($class)->where($this->map->meta($class)['id'], $id)->first();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class, string|int $id): object
    {
        return $this->find($class, $id) ?? throw new NotFound((new \ReflectionClass($class))->getShortName() . " $id not found.");
    }

    /** Inserts a new entity or updates the changed columns of a loaded one. */
    public function save(object $entity): void
    {
        $meta = $this->map->meta($entity::class);
        $id = $this->idOf($entity, $meta);
        if ($id !== null && isset($this->snapshots[$entity::class][$id])) {
            $this->update($entity, $meta, $id);
        } else {
            $this->insert([$entity], $meta);
        }
    }

    /**
     * Saves many entities: new ones of the same class are inserted in multi-row batches.
     *
     * @param list<object> $entities
     */
    public function saveAll(array $entities): void
    {
        $new = [];
        foreach ($entities as $entity) {
            $meta = $this->map->meta($entity::class);
            $id = $this->idOf($entity, $meta);
            if ($id !== null && isset($this->snapshots[$entity::class][$id])) {
                $this->update($entity, $meta, $id);
            } else {
                $new[$entity::class][] = $entity;
            }
        }
        foreach ($new as $class => $batch) {
            $meta = $this->map->meta($class);
            foreach (array_chunk($batch, max(1, intdiv(900, max(1, \count($meta['columns']))))) as $chunk) {
                $this->insert($chunk, $meta);
            }
        }
    }

    /** Deletes (or soft-deletes) an entity. */
    public function delete(object $entity, bool $force = false): void
    {
        $meta = $this->map->meta($entity::class);
        if ($meta['softDelete'] !== null && !$force) {
            $this->map->set($entity, $meta['softDelete'], $this->now());
            $this->save($entity);

            return;
        }
        $id = $this->idOf($entity, $meta) ?? throw new \LogicException('Cannot delete an entity that was never saved.');
        [$where, $params] = $this->rowCondition($entity, $meta, $id);
        if ($this->db->executeBuilt('DELETE FROM ' . $this->q($meta['table']) . $where, $params) === 0 && $meta['version'] !== null) {
            throw new StaleEntity((new \ReflectionClass($entity))->getShortName() . " $id was changed or deleted by someone else.");
        }
        unset($this->identity[$entity::class][$id], $this->snapshots[$entity::class][$id]);
    }

    public function restore(object $entity): void
    {
        $meta = $this->map->meta($entity::class);
        if ($meta['softDelete'] === null) {
            throw new \LogicException($entity::class . ' has no #[SoftDelete] property.');
        }
        $this->map->set($entity, $meta['softDelete'], null);
        $this->save($entity);
    }

    /**
     * Inserts or updates by a unique key in one statement per batch (ON CONFLICT, ON
     * DUPLICATE KEY or MERGE, depending on the database).
     *
     * @param list<object> $entities of one class
     * @param list<string> $uniqueBy properties of a unique key
     * @param list<string>|null $update properties to overwrite on conflict (default: all but the key and id)
     */
    public function upsert(array $entities, array $uniqueBy, ?array $update = null): void
    {
        if ($entities === []) {
            return;
        }
        $meta = $this->map->meta($entities[0]::class);
        $rows = array_map(fn (object $e): array => $this->prepareInsert($e, $meta), $entities);
        $columns = array_keys($rows[0]);
        $conflict = array_map(static fn (string $p): string => EntityMap::column($meta, $p)['column'], $uniqueBy);
        $updateColumns = $update === null
            ? array_values(array_diff($columns, [...$conflict, $meta['idColumn'], ...($meta['createdAt'] === null ? [] : [$meta['columns'][$meta['createdAt']]['column']])]))
            : array_map(static fn (string $p): string => EntityMap::column($meta, $p)['column'], $update);
        $dialect = $this->dialect();
        $batches = \in_array($dialect->name(), ['pgsql', 'sqlite', 'mysql'], true) ? array_chunk($rows, max(1, intdiv(900, \count($columns)))) : array_map(static fn (array $r): array => [$r], $rows);
        foreach ($batches as $batch) {
            $params = [];
            foreach ($batch as $row) {
                array_push($params, ...array_values($row));
            }
            $this->db->executeBuilt($dialect->upsert($meta['table'], $columns, \count($batch), $conflict, $updateColumns), $params);
        }
    }

    /**
     * Rewrites every #[Encrypted] column of an entity with the current key (`crypto.current`),
     * across tenants and including soft-deleted rows. Run it after rotating keys, then remove the
     * old key. Rows are streamed, so memory stays flat.
     *
     * @param class-string $class
     * @return int rows rewritten
     */
    public function reencrypt(string $class, int $batch = 500): int
    {
        $meta = $this->map->meta($class);
        $columns = array_keys($this->map->encryptedColumns($class));
        if ($columns === []) {
            return 0;
        }
        $dialect = $this->dialect();
        $sql = 'UPDATE ' . $this->q($meta['table']) . ' SET ' . implode(', ', array_map(fn (string $c): string => $this->q($c) . ' = ?', $columns))
            . ' WHERE ' . $this->q($meta['idColumn']) . ' = ?';

        return $this->tenant->acrossTenants(function () use ($class, $meta, $columns, $dialect, $sql, $batch): int {
            $count = 0;
            foreach ($this->query($class)->unsafeAcrossTenants()->withTrashed()->lazy($batch) as $entity) {
                $row = $this->map->seal($class, $this->map->extract($entity, $dialect));
                $params = array_map(static fn (string $c): string|int|float|bool|null => $row[$c] ?? null, $columns);
                $params[] = $row[$meta['idColumn']] ?? null;
                $count += $this->db->executeBuilt($sql, $params);
            }

            return $count;
        });
    }

    /**
     * Eager-loads relations onto entities already fetched: load($orders, 'lines.product').
     *
     * @param object|list<object> $entities
     */
    public function load(object|array $entities, string ...$relations): void
    {
        $entities = \is_array($entities) ? $entities : [$entities];
        if ($entities === []) {
            return;
        }
        $this->loadTree($entities, self::tree(array_values($relations)));
    }

    /**
     * Adds rows to a BelongsToMany pivot table.
     *
     * @param list<string|int> $relatedIds
     */
    public function attach(object $entity, string $relation, array $relatedIds): void
    {
        [$pivot, $local, $related, $id] = $this->pivot($entity, $relation);
        foreach ($relatedIds as $relatedId) {
            $this->db->executeBuilt('INSERT INTO ' . $this->q($pivot) . ' (' . $this->q($local) . ', ' . $this->q($related) . ') VALUES (?, ?)', [$id, $relatedId]);
        }
    }

    /** @param list<string|int>|null $relatedIds null detaches all */
    public function detach(object $entity, string $relation, ?array $relatedIds = null): void
    {
        [$pivot, $local, $related, $id] = $this->pivot($entity, $relation);
        $sql = 'DELETE FROM ' . $this->q($pivot) . ' WHERE ' . $this->q($local) . ' = ?';
        $params = [$id];
        if ($relatedIds !== null) {
            if ($relatedIds === []) {
                return;
            }
            $sql .= ' AND ' . $this->q($related) . ' IN (' . implode(', ', array_fill(0, \count($relatedIds), '?')) . ')';
            array_push($params, ...$relatedIds);
        }
        $this->db->executeBuilt($sql, $params);
    }

    /**
     * @template R
     * @param \Closure(self): R $work
     * @return R
     */
    public function transaction(\Closure $work): mixed
    {
        return $this->db->transaction(fn (): mixed => $work($this));
    }

    public function newId(): string
    {
        return $this->ids->generate();
    }

    /** Forgets every tracked entity (e.g. between batches of a long job). */
    public function clear(): void
    {
        $this->identity = [];
        $this->snapshots = [];
    }

    public function dialect(): Dialect
    {
        return $this->dialect ??= Dialects::for($this->db->driver(), $this->dialectOverride);
    }

    /** @internal */
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * @internal turns rows into tracked entities, reusing instances already in the identity map
     *
     * @template T of object
     * @param class-string<T> $class
     * @param list<Row> $rows
     * @return list<T>
     */
    public function materialize(string $class, array $rows): array
    {
        $meta = $this->map->meta($class);
        $dialect = $this->dialect();
        $entities = [];
        foreach ($rows as $row) {
            $data = $row->toArray();
            $id = \is_scalar($data[$meta['idColumn']] ?? null) ? (string) $data[$meta['idColumn']] : '';
            $known = $this->identity[$class][$id] ?? null;
            if ($known instanceof $class) {
                $entities[] = $known;
                continue;
            }
            $entity = $this->map->hydrate($class, $data);
            $this->identity[$class][$id] = $entity;
            $this->snapshots[$class][$id] = $this->map->extract($entity, $dialect);
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * @param list<object> $entities
     * @param Meta $meta
     */
    private function insert(array $entities, array $meta): void
    {
        $rows = array_map(fn (object $e): array => $this->prepareInsert($e, $meta), $entities);
        $columns = array_keys($rows[0]);
        $dialect = $this->dialect();
        $auto = $meta['idGenerated'] === 'auto' && !\in_array($meta['idColumn'], $columns, true);
        $params = [];
        foreach ($rows as $row) {
            array_push($params, ...array_values($row));
        }
        $values = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        $into = 'INSERT INTO ' . $this->q($meta['table']) . ' (' . implode(', ', array_map($this->q(...), $columns)) . ')';
        if (!$auto) {
            $this->db->executeBuilt($into . ' VALUES ' . implode(', ', array_fill(0, \count($rows), $values)), $params);
        } else {
            foreach ($entities as $i => $entity) {
                $mode = $dialect->generatedKeys();
                $sql = match ($mode) {
                    'returning' => $into . ' VALUES ' . $values . ' RETURNING ' . $this->q($meta['idColumn']),
                    'output' => $into . ' OUTPUT INSERTED.' . $this->q($meta['idColumn']) . ' VALUES ' . $values,
                    'last_insert_id' => $into . ' VALUES ' . $values,
                    default => throw new \LogicException("Auto-increment ids cannot be read back on {$dialect->name()}; use #[Id] (uuid) instead."),
                };
                $id = $this->db->insertBuilt($sql, array_values($rows[$i]), $mode !== 'last_insert_id');
                $this->map->set($entity, $meta['id'], (int) $id);
            }
        }
        foreach ($entities as $entity) {
            $id = (string) $this->idOf($entity, $meta);
            $this->identity[$entity::class][$id] = $entity;
            $this->snapshots[$entity::class][$id] = $this->map->extract($entity, $dialect);
        }
    }

    /**
     * Assigns id, tenant, timestamps and version, then extracts the row to insert.
     *
     * @param Meta $meta
     * @return array<string, scalar|null>
     */
    private function prepareInsert(object $entity, array $meta): array
    {
        $now = $this->now();
        if ($meta['idGenerated'] === 'uuid' && $this->idOf($entity, $meta) === null) {
            if (!\in_array($meta['id'], $meta['managed'], true)) {
                throw new \LogicException($entity::class . " has a readonly id: pass one when creating it, e.g. \$orm->newId().");
            }
            $this->map->set($entity, $meta['id'], $this->newId());
        }
        if ($meta['tenant'] !== null) {
            $this->stampTenant($entity, $meta);
        }
        if ($meta['createdAt'] !== null && $this->value($entity, $meta, $meta['createdAt']) === null) {
            $this->map->set($entity, $meta['createdAt'], $now);
        }
        if ($meta['updatedAt'] !== null) {
            $this->map->set($entity, $meta['updatedAt'], $now);
        }
        if ($meta['version'] !== null && $this->value($entity, $meta, $meta['version']) === 0) {
            $this->map->set($entity, $meta['version'], 1);
        }
        $row = $this->map->extract($entity, $this->dialect());
        if ($meta['idGenerated'] === 'auto' && $row[$meta['idColumn']] === null) {
            unset($row[$meta['idColumn']]);
        }

        return $this->map->seal($entity::class, $row);
    }

    /** @param Meta $meta */
    private function update(object $entity, array $meta, string $id): void
    {
        $dialect = $this->dialect();
        $snapshot = $this->snapshots[$entity::class][$id];
        $changed = array_diff_assoc(array_map(self::comparable(...), $this->map->extract($entity, $dialect)), array_map(self::comparable(...), $snapshot));
        if ($changed === []) {
            return;
        }
        if ($meta['tenant'] !== null) {
            $this->stampTenant($entity, $meta);
        }
        if ($meta['updatedAt'] !== null) {
            $this->map->set($entity, $meta['updatedAt'], $this->now());
        }
        $previousVersion = null;
        if ($meta['version'] !== null) {
            $previousVersion = (int) $this->value($entity, $meta, $meta['version']);
            $this->map->set($entity, $meta['version'], $previousVersion + 1);
        }
        $current = $this->map->extract($entity, $dialect);
        $columns = array_keys(array_diff_assoc(array_map(self::comparable(...), $current), array_map(self::comparable(...), $snapshot)));
        $set = [];
        $params = [];
        foreach ($columns as $column) {
            $set[] = $this->q($column) . ' = ?';
            $params[] = $this->map->sealValue($entity::class, $column, $current[$column]);
        }
        [$where, $whereParams] = $this->rowCondition($entity, $meta, $id, $previousVersion);
        $affected = $this->db->executeBuilt('UPDATE ' . $this->q($meta['table']) . ' SET ' . implode(', ', $set) . $where, [...$params, ...$whereParams]);
        if ($affected === 0 && $meta['version'] !== null) {
            $this->map->set($entity, $meta['version'], $previousVersion);
            throw new StaleEntity((new \ReflectionClass($entity))->getShortName() . " $id was changed by someone else since it was read. Reload it and try again.");
        }
        $this->snapshots[$entity::class][$id] = $current;
    }

    /**
     * WHERE for one row: its id, its tenant, and its version when versioned.
     *
     * @param Meta $meta
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function rowCondition(object $entity, array $meta, string $id, ?int $version = null): array
    {
        $where = [$this->q($meta['idColumn']) . ' = ?'];
        $params = [$meta['columns'][$meta['id']]['type'] === 'int' ? (int) $id : $id];
        if ($meta['tenant'] !== null) {
            $where[] = $this->q($meta['columns'][$meta['tenant']]['column']) . ' = ?';
            $params[] = (string) $this->value($entity, $meta, $meta['tenant']);
        }
        if ($meta['version'] !== null) {
            $where[] = $this->q($meta['columns'][$meta['version']]['column']) . ' = ?';
            $params[] = $version ?? (int) $this->value($entity, $meta, $meta['version']);
        }

        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    /** @param Meta $meta */
    private function stampTenant(object $entity, array $meta): void
    {
        $property = (string) $meta['tenant'];
        $value = $this->value($entity, $meta, $property);
        $current = $this->tenant->current();
        if ($this->tenant->isCrossTenant()) {
            return;
        }
        if ($current === null) {
            throw new TenantViolation($entity::class . ' belongs to a tenant, but the scope has none.');
        }
        if ($value === '' || $value === null) {
            $this->map->set($entity, $property, $current);
        } elseif ($value !== $current) {
            throw new TenantViolation($entity::class . " belongs to tenant $value; the current tenant is $current.");
        }
    }

    /**
     * @param list<object> $entities of one class
     * @param array<string, array<mixed>> $tree relation => nested relations
     */
    private function loadTree(array $entities, array $tree): void
    {
        $meta = $this->map->meta($entities[0]::class);
        foreach ($tree as $relation => $children) {
            $definition = $meta['relations'][$relation] ?? throw new \InvalidArgumentException("{$meta['class']} has no relation \"$relation\". Relations: " . implode(', ', array_keys($meta['relations'])) . '.');
            $target = $this->map->meta($definition['target']);
            $related = match ($definition['kind']) {
                'hasMany', 'hasOne' => $this->loadOwned($entities, $meta, $relation, $definition, $target),
                'belongsTo' => $this->loadOwner($entities, $meta, $relation, $definition, $target),
                default => $this->loadPivot($entities, $meta, $relation, $definition, $target),
            };
            if ($children !== [] && $related !== []) {
                /** @var array<string, array<mixed>> $children */
                $this->loadTree($related, $children);
            }
        }
    }

    /**
     * @param list<object> $entities
     * @param Meta $meta
     * @param array{kind: string, target: class-string, key: string, orderBy?: ?string} $definition
     * @param Meta $target
     * @return list<object>
     */
    private function loadOwned(array $entities, array $meta, string $relation, array $definition, array $target): array
    {
        $ids = array_values(array_unique(array_map(fn (object $e): string => (string) $this->idOf($e, $meta), $entities)));
        $children = $this->fetchIn($target, $definition['key'], $ids, $definition['orderBy'] ?? null);
        $byParent = [];
        foreach ($children as $child) {
            $byParent[(string) $this->value($child, $target, $definition['key'])][] = $child;
        }
        foreach ($entities as $entity) {
            $mine = $byParent[(string) $this->idOf($entity, $meta)] ?? [];
            $container = $this->container($entity, $meta, $relation);
            $container instanceof Many ? $container->fill($mine) : $container->fill($mine[0] ?? null);
        }

        return $children;
    }

    /**
     * @param list<object> $entities
     * @param Meta $meta
     * @param array{kind: string, target: class-string, key: string} $definition
     * @param Meta $target
     * @return list<object>
     */
    private function loadOwner(array $entities, array $meta, string $relation, array $definition, array $target): array
    {
        $keys = [];
        foreach ($entities as $entity) {
            $key = $this->value($entity, $meta, $definition['key']);
            if ($key !== null && $key !== '') {
                $keys[(string) $key] = true;
            }
        }
        $owners = $this->fetchIn($target, $target['id'], array_keys($keys));
        $byId = [];
        foreach ($owners as $owner) {
            $byId[(string) $this->idOf($owner, $target)] = $owner;
        }
        foreach ($entities as $entity) {
            $container = $this->container($entity, $meta, $relation);
            $owner = $byId[(string) $this->value($entity, $meta, $definition['key'])] ?? null;
            $container instanceof One ? $container->fill($owner) : $container->fill($owner === null ? [] : [$owner]);
        }

        return $owners;
    }

    /**
     * @param list<object> $entities
     * @param Meta $meta
     * @param array{kind: string, target: class-string, key: string, pivot?: string, local?: string, related?: string} $definition
     * @param Meta $target
     * @return list<object>
     */
    private function loadPivot(array $entities, array $meta, string $relation, array $definition, array $target): array
    {
        $ids = array_values(array_unique(array_map(fn (object $e): string => (string) $this->idOf($e, $meta), $entities)));
        $local = (string) ($definition['local'] ?? '');
        $related = (string) ($definition['related'] ?? '');
        $links = [];
        foreach (array_chunk($ids, $this->dialect()->maxInList()) as $chunk) {
            $sql = 'SELECT ' . $this->q($local) . ' AS l, ' . $this->q($related) . ' AS r FROM ' . $this->q((string) ($definition['pivot'] ?? ''))
                . ' WHERE ' . $this->q($local) . ' IN (' . implode(', ', array_fill(0, \count($chunk), '?')) . ')';
            foreach ($this->db->selectBuilt($sql, $chunk) as $row) {
                $data = $row->toArray();
                $links[] = [self::str($data['l'] ?? ''), self::str($data['r'] ?? '')];
            }
        }
        $targets = $this->fetchIn($target, $target['id'], array_values(array_unique(array_column($links, 1))));
        $byId = [];
        foreach ($targets as $t) {
            $byId[(string) $this->idOf($t, $target)] = $t;
        }
        $byParent = [];
        foreach ($links as [$l, $r]) {
            if (isset($byId[$r])) {
                $byParent[$l][] = $byId[$r];
            }
        }
        foreach ($entities as $entity) {
            $container = $this->container($entity, $meta, $relation);
            $mine = $byParent[(string) $this->idOf($entity, $meta)] ?? [];
            $container instanceof Many ? $container->fill($mine) : $container->fill($mine[0] ?? null);
        }

        return $targets;
    }

    /**
     * @param Meta $meta
     * @param list<string> $values
     * @return list<object>
     */
    private function fetchIn(array $meta, string $property, array $values, ?string $orderBy = null): array
    {
        $result = [];
        foreach (array_chunk($values, $this->dialect()->maxInList()) as $chunk) {
            $query = $this->query($meta['class'])->whereIn($property, $chunk);
            if ($orderBy !== null) {
                $query->orderBy($orderBy);
            }
            array_push($result, ...$query->get());
        }

        return $result;
    }

    /**
     * 'a.b', 'a.c', 'd' => ['a' => ['b' => [], 'c' => []], 'd' => []]
     *
     * @param list<string> $paths
     * @return array<string, array<mixed>>
     */
    private static function tree(array $paths): array
    {
        $groups = [];
        foreach ($paths as $path) {
            $parts = explode('.', $path, 2);
            $groups[$parts[0]] ??= [];
            if (isset($parts[1])) {
                $groups[$parts[0]][] = $parts[1];
            }
        }

        return array_map(self::tree(...), $groups);
    }

    /**
     * @param Meta $meta
     * @return Many<object>|One<object>
     */
    private function container(object $entity, array $meta, string $relation): Many|One
    {
        $container = get_object_vars($entity)[$relation] ?? null;

        return $container instanceof Many || $container instanceof One
            ? $container
            : throw new \LogicException("{$meta['class']}::\$$relation is not initialised.");
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} pivot, local column, related column, entity id */
    private function pivot(object $entity, string $relation): array
    {
        $meta = $this->map->meta($entity::class);
        $definition = $meta['relations'][$relation] ?? null;
        if ($definition === null || $definition['kind'] !== 'belongsToMany') {
            throw new \InvalidArgumentException("{$meta['class']}::\$$relation is not a #[BelongsToMany] relation.");
        }

        return [(string) ($definition['pivot'] ?? ''), (string) ($definition['local'] ?? ''), (string) ($definition['related'] ?? ''), (string) $this->idOf($entity, $meta)];
    }

    /** @param Meta $meta */
    private function idOf(object $entity, array $meta): ?string
    {
        $value = $this->value($entity, $meta, $meta['id']);

        return $value === null || $value === '' || $value === 0 ? null : (string) $value;
    }

    /** @param Meta $meta */
    private function value(object $entity, array $meta, string $property): string|int|float|bool|null
    {
        $raw = get_object_vars($entity)[$property] ?? null;

        return match (true) {
            $raw === null, \is_scalar($raw) => $raw,
            $raw instanceof \BackedEnum => $raw->value,
            $raw instanceof \DateTimeInterface => Values::toDateTime($raw),
            default => throw new \LogicException("{$meta['class']}::\$$property is not a scalar column."),
        };
    }

    private function q(string $identifier): string
    {
        return $this->dialect()->quote($identifier);
    }

    private static function comparable(mixed $value): string
    {
        return $value === null ? "\0null" : (\is_bool($value) ? ($value ? '1' : '0') : self::str($value));
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
