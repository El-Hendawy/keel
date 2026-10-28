<?php

declare(strict_types=1);

namespace Keel\Orm;

use Keel\Orm\Dialect\Dialect;
use Keel\Tenancy\TenantContext;

/**
 * Conditions on one entity, by property name. Names are checked against the compiled
 * metadata, operators against a fixed list, and values are always bound: no input can
 * reach the SQL text. The PHPStan extension (extension.neon) checks names and value types
 * during analysis.
 *
 * @template T of object
 * @phpstan-import-type Meta from EntityMap
 */
class Criteria
{
    private const array OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    /** @var list<array{0: string, 1: string, 2: list<scalar|null>}> [AND|OR, sql, params] */
    protected array $parts = [];

    /** @param Meta $meta */
    public function __construct(
        protected readonly EntityMap $map,
        protected readonly array $meta,
        protected readonly Dialect $dialect,
        protected readonly TenantContext $tenant,
        protected readonly string $alias,
        protected int $depth = 0,
    ) {
    }

    /**
     * where('status', OrderStatus::Paid), where('total', '>', 100), or a group:
     * where(fn (Criteria $c) => $c->where('a', 1)->orWhere('b', 2)).
     */
    public function where(string|\Closure $property, mixed $operator = null, mixed $value = null): static
    {
        return $this->add('AND', $property, $operator, $value, \func_num_args());
    }

    public function orWhere(string|\Closure $property, mixed $operator = null, mixed $value = null): static
    {
        return $this->add('OR', $property, $operator, $value, \func_num_args());
    }

    /** @param list<mixed> $values */
    public function whereIn(string $property, array $values): static
    {
        return $this->in('AND', $property, $values, false);
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $property, array $values): static
    {
        return $this->in('AND', $property, $values, true);
    }

    public function whereNull(string $property): static
    {
        $this->parts[] = ['AND', $this->col($property) . ' IS NULL', []];

        return $this;
    }

    public function whereNotNull(string $property): static
    {
        $this->parts[] = ['AND', $this->col($property) . ' IS NOT NULL', []];

        return $this;
    }

    public function whereBetween(string $property, mixed $from, mixed $to): static
    {
        $column = EntityMap::column($this->meta, $property);
        $this->parts[] = ['AND', $this->col($property) . ' BETWEEN ? AND ?', [EntityMap::toDb($column, $from, $this->dialect), EntityMap::toDb($column, $to, $this->dialect)]];

        return $this;
    }

    /**
     * Rows that have at least one related row (matching $conditions): EXISTS, not a join,
     * so the result never repeats rows.
     *
     * @param (\Closure(Criteria<object>): mixed)|null $conditions
     */
    public function whereHas(string $relation, ?\Closure $conditions = null): static
    {
        return $this->exists($relation, $conditions, false);
    }

    /** @param (\Closure(Criteria<object>): mixed)|null $conditions */
    public function whereDoesntHave(string $relation, ?\Closure $conditions = null): static
    {
        return $this->exists($relation, $conditions, true);
    }

    /** @return array{0: string, 1: list<scalar|null>} */
    public function compile(): array
    {
        $sql = '';
        $params = [];
        foreach ($this->parts as $i => [$glue, $part, $partParams]) {
            $sql .= ($i === 0 ? '' : " $glue ") . $part;
            array_push($params, ...$partParams);
        }

        return [$sql, $params];
    }

    /**
     * Implicit filters: the current tenant, and soft-deleted rows.
     *
     * @return array{0: list<string>, 1: list<scalar|null>}
     */
    protected function scope(bool $withTrashed = false, bool $onlyTrashed = false, bool $acrossTenants = false): array
    {
        $sql = [];
        $params = [];
        if ($this->meta['tenant'] !== null && !$acrossTenants && !$this->tenant->isCrossTenant()) {
            $sql[] = $this->col($this->meta['tenant']) . ' = ?';
            $params[] = $this->tenant->id();
        }
        if ($this->meta['softDelete'] !== null && ($onlyTrashed || !$withTrashed)) {
            $sql[] = $this->col($this->meta['softDelete']) . ($onlyTrashed ? ' IS NOT NULL' : ' IS NULL');
        }

        return [$sql, $params];
    }

    protected function col(string $property): string
    {
        return $this->alias . '.' . $this->dialect->quote(EntityMap::column($this->meta, $property)['column']);
    }

    private function add(string $glue, string|\Closure $property, mixed $operator, mixed $value, int $arguments): static
    {
        if ($property instanceof \Closure) {
            $group = new self($this->map, $this->meta, $this->dialect, $this->tenant, $this->alias, $this->depth);
            $property($group);
            [$sql, $params] = $group->compile();
            if ($sql !== '') {
                $this->parts[] = [$glue, "($sql)", $params];
            }

            return $this;
        }
        if ($arguments === 2) {
            [$operator, $value] = ['=', $operator];
        }
        $operator = strtolower(\is_string($operator) ? $operator : '');
        if (!\in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException('Unsupported operator. Use one of: ' . implode(', ', self::OPERATORS) . '.');
        }
        $column = EntityMap::column($this->meta, $property);
        if ($value === null) {
            $this->parts[] = [$glue, $this->col($property) . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL'), []];

            return $this;
        }
        $this->parts[] = [$glue, $this->col($property) . ' ' . strtoupper($operator) . ' ?', [EntityMap::toDb($column, $value, $this->dialect)]];

        return $this;
    }

    /** @param list<mixed> $values */
    private function in(string $glue, string $property, array $values, bool $not): static
    {
        $column = EntityMap::column($this->meta, $property);
        if ($values === []) {
            $this->parts[] = [$glue, $not ? '1 = 1' : '1 = 0', []];

            return $this;
        }
        $converted = array_map(fn (mixed $v): string|int|float|bool|null => EntityMap::toDb($column, $v, $this->dialect), $values);
        $this->parts[] = [$glue, $this->col($property) . ($not ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, \count($converted), '?')) . ')', $converted];

        return $this;
    }

    /** @param (\Closure(Criteria<object>): mixed)|null $conditions */
    private function exists(string $relation, ?\Closure $conditions, bool $not): static
    {
        $definition = $this->meta['relations'][$relation] ?? throw new \InvalidArgumentException("{$this->meta['class']} has no relation \"$relation\".");
        $target = $this->map->meta($definition['target']);
        $alias = 't' . ($this->depth + 1);
        $q = $this->dialect->quote(...);
        $sub = new self($this->map, $target, $this->dialect, $this->tenant, $alias, $this->depth + 1);
        $from = $q($target['table']) . ' ' . $alias;
        $link = match ($definition['kind']) {
            'hasMany', 'hasOne' => "$alias." . $q($target['columns'][$definition['key']]['column']) . " = {$this->alias}." . $q($this->meta['idColumn']),
            'belongsTo' => "$alias." . $q($target['idColumn']) . " = {$this->alias}." . $q($this->meta['columns'][$definition['key']]['column']),
            default => "$alias." . $q($target['idColumn']) . ' IN (SELECT p.' . $q((string) ($definition['related'] ?? '')) . ' FROM ' . $q((string) ($definition['pivot'] ?? '')) . ' p WHERE p.' . $q((string) ($definition['local'] ?? '')) . " = {$this->alias}." . $q($this->meta['idColumn']) . ')',
        };
        if ($conditions !== null) {
            $conditions($sub);
        }
        [$scopeSql, $scopeParams] = $sub->scope();
        [$subSql, $subParams] = $sub->compile();
        $where = implode(' AND ', array_filter([$link, ...$scopeSql, $subSql === '' ? '' : "($subSql)"]));
        $this->parts[] = ['AND', ($not ? 'NOT ' : '') . "EXISTS (SELECT 1 FROM $from WHERE $where)", [...$scopeParams, ...$subParams]];

        return $this;
    }
}
