<?php

declare(strict_types=1);

namespace Keel\Build\Orm;

use Keel\Build\Php;
use Keel\Orm\Attribute as A;
use Keel\Orm\Many;
use Keel\Orm\One;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Compiles #[Entity] classes: validates every mapping and writes metadata plus one
 * hydrator, extractor and setter per entity, so the ORM never reflects at runtime.
 */
final class OrmCompiler
{
    /** @var list<string> */
    public array $errors = [];

    /** @var array<class-string, array<string, mixed>> */
    private array $meta = [];

    /** @var array<class-string, string> */
    private array $methods = [];

    /** @var array<class-string, string> class => table */
    private array $tables = [];

    /** @var array<class-string, list<string>> class => column properties */
    private array $columnNames = [];

    /** @var array<class-string, array<string, array{kind: string, target: string, key: string, orderBy: ?string}>> */
    private array $relationIndex = [];

    /** @param class-string $class */
    public function entity(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $entity = ($reflection->getAttributes(A\Entity::class)[0] ?? null)?->newInstance();
        if ($entity === null) {
            return;
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            $this->errors[] = "Entity $class needs a constructor whose promoted properties are its columns.";

            return;
        }
        $id = self::id($class);
        $columns = [];
        $relations = [];
        $managed = [];
        $meta = ['class' => $class, 'table' => $entity->table, 'id' => null, 'idColumn' => '', 'idGenerated' => 'uuid', 'tenant' => null, 'version' => null, 'createdAt' => null, 'updatedAt' => null, 'softDelete' => null];
        $hydrate = [];
        $extract = [];
        $setters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $where = "$class::\$$name";
            $type = $parameter->getType();
            if (!$parameter->isPromoted()) {
                $this->errors[] = "$where must be a promoted property (public, in the constructor) to be mapped.";
                continue;
            }
            if (!$type instanceof ReflectionNamedType) {
                $this->errors[] = "$where needs a single named type.";
                continue;
            }
            $property = $reflection->getProperty($name);
            if (!$property->isPublic()) {
                $this->errors[] = "$where must be public.";
                continue;
            }
            if ($this->relation($class, $parameter, $type, $relations)) {
                continue;
            }

            $column = ($parameter->getAttributes(A\Column::class)[0] ?? null)?->newInstance() ?? new A\Column();
            $definition = $this->column($where, $type, $column);
            if ($definition === null) {
                continue;
            }
            $definition['column'] = $column->name ?? self::snake($name);
            $definition['readonly'] = $property->isReadOnly();
            $columns[$name] = $definition;

            $idAttribute = ($parameter->getAttributes(A\Id::class)[0] ?? null)?->newInstance();
            if ($idAttribute !== null) {
                if ($meta['id'] !== null) {
                    $this->errors[] = "Entity $class has two #[Id] properties.";
                }
                if (!\in_array($idAttribute->generated, ['uuid', 'auto', 'none'], true)) {
                    $this->errors[] = "$where: #[Id(generated: ...)] must be uuid, auto or none.";
                }
                if ($idAttribute->generated === 'auto' && ($definition['type'] !== 'int' || $property->isReadOnly())) {
                    $this->errors[] = "$where: an auto-increment id must be a non-readonly int (e.g. `public ?int \$id = null`).";
                }
                $meta['id'] = $name;
                $meta['idColumn'] = $definition['column'];
                $meta['idGenerated'] = $idAttribute->generated;
                if (!$property->isReadOnly()) {
                    $managed[] = $name;
                }
            }
            foreach (['tenant' => A\TenantKey::class, 'version' => A\Version::class, 'createdAt' => A\CreatedAt::class, 'updatedAt' => A\UpdatedAt::class, 'softDelete' => A\SoftDelete::class] as $role => $attribute) {
                if ($parameter->getAttributes($attribute) === []) {
                    continue;
                }
                $expected = match ($role) {
                    'tenant' => 'string',
                    'version' => 'int',
                    default => 'datetime',
                };
                if ($definition['type'] !== $expected || ($role !== 'tenant' && $property->isReadOnly()) || (\in_array($role, ['createdAt', 'updatedAt', 'softDelete'], true) && !$type->allowsNull())) {
                    $this->errors[] = "$where: #[" . (new ReflectionClass($attribute))->getShortName() . "] must be a non-readonly " . ($expected === 'datetime' ? '?DateTimeImmutable (null until set)' : $expected) . ($role === 'tenant' ? '' : '') . '.';
                    continue;
                }
                $meta[$role] = $name;
                if (!$property->isReadOnly()) {
                    $managed[] = $name;
                }
            }

            $hydrate[] = '            ' . $name . ': ' . self::fromDb($definition, '$r[' . Php::string($definition['column']) . ']') . ',';
            $extract[] = '            ' . Php::string($definition['column']) . ' => ' . self::toDb($definition, "\$e->$name") . ',';
            if (!$property->isReadOnly()) {
                $setters[$name] = self::setter($definition, $name);
            }
        }
        if ($meta['id'] === null) {
            $this->errors[] = "Entity $class has no #[Id] property.";
        }
        $indexes = [];
        foreach ($reflection->getAttributes(A\Index::class) as $attribute) {
            $index = $attribute->newInstance();
            foreach ($index->properties as $p) {
                if (!isset($columns[$p])) {
                    $this->errors[] = "#[Index] on $class names $p, which is not a column property.";
                    continue 2;
                }
            }
            $indexes[] = ['columns' => array_map(static fn (string $p): string => $columns[$p]['column'], $index->properties), 'unique' => $index->unique];
        }
        $other = array_search($entity->table, $this->tables, true);
        if ($other !== false) {
            $this->errors[] = "Entities $other and $class both map table {$entity->table}.";
        }
        $this->tables[$class] = $entity->table;
        $this->columnNames[$class] = array_keys($columns);
        $this->relationIndex[$class] = array_map(static fn (array $r): array => [
            'kind' => $r['kind'],
            'target' => $r['target'],
            'key' => $r['key'],
            'orderBy' => $r['orderBy'] ?? null,
        ], $relations);

        $this->meta[$class] = $meta + [
            'columns' => $columns,
            'relations' => $relations,
            'indexes' => $indexes,
            'managed' => array_values(array_unique($managed)),
            'hydrator' => "h_$id",
            'extractor' => "x_$id",
            'setter' => "s_$id",
        ];
        $arms = array_map(static fn (string $code): string => "            $code,", array_values(array_intersect_key($setters, array_flip($managed))));
        $this->methods[$class] = "    /** @param array<string, mixed> \$r */\n"
            . "    public static function h_$id(array \$r): \\$class\n    {\n        return new \\$class(\n" . implode("\n", $hydrate) . "\n        );\n    }\n\n"
            . "    /** @return array<string, scalar|null> */\n"
            . "    public static function x_$id(\\$class \$e, \\Keel\\Orm\\Dialect\\Dialect \$d): array\n    {\n        return [\n" . implode("\n", $extract) . "\n        ];\n    }\n\n"
            . "    public static function s_$id(\\$class \$e, string \$property, mixed \$v): void\n    {\n        match (\$property) {\n"
            . implode("\n", $arms) . ($arms === [] ? '' : "\n")
            . "            default => throw new \\LogicException(\"\$property is not managed by the ORM on \" . \\$class::class . '.'),\n        };\n    }\n";
    }

    /** Checks relation targets once every entity is known. */
    public function finish(): void
    {
        foreach ($this->relationIndex as $class => $relations) {
            foreach ($relations as $name => $relation) {
                $target = $relation['target'];
                if (!isset($this->columnNames[$target])) {
                    $this->errors[] = "$class::\$$name targets $target, which is not an #[Entity].";
                    continue;
                }
                $owner = $relation['kind'] === 'belongsTo' ? $class : $target;
                if ($relation['kind'] !== 'belongsToMany' && !\in_array($relation['key'], $this->columnNames[$owner] ?? [], true)) {
                    $this->errors[] = "$class::\$$name: foreignKey {$relation['key']} is not a column property of $owner.";
                }
                if ($relation['orderBy'] !== null && !\in_array($relation['orderBy'], $this->columnNames[$target], true)) {
                    $this->errors[] = "$class::\$$name: orderBy {$relation['orderBy']} is not a column property of $target.";
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->meta === [];
    }

    public function compileClass(string $namespace, string $class): string
    {
        ksort($this->methods);

        return "<?php\n\n// Generated by Keel. Do not edit: run `keel build` instead.\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\n"
            . "use Keel\\Orm\\Values as V;\n\nfinal class $class\n{\n"
            . '    public const array META = ' . Php::export($this->meta, 1) . ";\n\n"
            . implode("\n", $this->methods) . "}\n";
    }

    /** @param array<string, array{kind: string, target: string, key: string, orderBy?: ?string, pivot?: string, local?: string, related?: string}> $relations */
    private function relation(string $class, ReflectionParameter $parameter, ReflectionNamedType $type, array &$relations): bool
    {
        $name = $parameter->getName();
        foreach ([A\HasMany::class => 'hasMany', A\HasOne::class => 'hasOne', A\BelongsTo::class => 'belongsTo', A\BelongsToMany::class => 'belongsToMany'] as $attribute => $kind) {
            $instance = ($parameter->getAttributes($attribute)[0] ?? null)?->newInstance();
            if ($instance === null) {
                continue;
            }
            $container = \in_array($kind, ['hasMany', 'belongsToMany'], true) ? Many::class : One::class;
            if ($type->getName() !== $container || !$parameter->isDefaultValueAvailable()) {
                $this->errors[] = "$class::\$$name is a $kind relation: declare it as `public " . (new ReflectionClass($container))->getShortName() . " \$$name = new " . (new ReflectionClass($container))->getShortName() . '()`.';

                return true;
            }
            $relations[$name] = match (true) {
                $instance instanceof A\HasMany => ['kind' => $kind, 'target' => $instance->target, 'key' => $instance->foreignKey, 'orderBy' => $instance->orderBy],
                $instance instanceof A\HasOne, $instance instanceof A\BelongsTo => ['kind' => $kind, 'target' => $instance->target, 'key' => $instance->foreignKey],
                default => ['kind' => $kind, 'target' => $instance->target, 'key' => '', 'pivot' => $instance->pivot, 'local' => $instance->localColumn, 'related' => $instance->relatedColumn],
            };

            return true;
        }
        if (\in_array($type->getName(), [Many::class, One::class], true)) {
            $this->errors[] = "$class::\$$name is a relation container without #[HasMany], #[HasOne], #[BelongsTo] or #[BelongsToMany].";

            return true;
        }

        return false;
    }

    /** @return array{type: string, nullable: bool, length?: int, precision?: int, scale?: int, enum?: string, enum_int?: bool, unique?: bool, index?: bool}|null */
    private function column(string $where, ReflectionNamedType $type, A\Column $column): ?array
    {
        $php = $type->getName();
        $declared = $column->type;
        $kind = match (true) {
            $declared === 'text' && $php === 'string' => 'text',
            $declared === 'decimal' && $php === 'string' => 'decimal',
            $declared !== null => null,
            \in_array($php, ['string', 'int', 'float', 'bool'], true) => $php,
            $php === \DateTimeImmutable::class => 'datetime',
            $php === 'array' => 'json',
            enum_exists($php) && is_subclass_of($php, \BackedEnum::class) => 'enum',
            default => null,
        };
        if ($kind === null) {
            $this->errors[] = $declared !== null
                ? "$where: type: '$declared' needs a string property (text, decimal)."
                : "$where: $php cannot be a column. Use string, int, float, bool, DateTimeImmutable, a backed enum or array (JSON).";

            return null;
        }
        $definition = ['type' => $kind, 'nullable' => $type->allowsNull()];
        if ($kind === 'string') {
            $definition['length'] = $column->length;
        }
        if ($kind === 'decimal') {
            $definition['precision'] = $column->precision;
            $definition['scale'] = $column->scale;
        }
        if ($kind === 'enum') {
            $definition['enum'] = $php;
            $definition['enum_int'] = (new \ReflectionEnum($php))->getBackingType()?->getName() === 'int';
        }
        if ($column->unique) {
            $definition['unique'] = true;
        }
        if ($column->index) {
            $definition['index'] = true;
        }

        return $definition;
    }

    /** @param array{type: string, nullable: bool, enum?: string, enum_int?: bool} $c */
    private static function fromDb(array $c, string $raw): string
    {
        $value = match ($c['type']) {
            'string', 'text' => "V::string($raw)",
            'decimal' => "V::decimal($raw)",
            'int' => "V::int($raw)",
            'float' => "V::float($raw)",
            'bool' => "V::bool($raw)",
            'datetime' => "V::dateTime($raw)",
            'json' => "V::json($raw)",
            default => '\\' . ($c['enum'] ?? '') . '::from(' . (($c['enum_int'] ?? false) ? "V::int($raw)" : "V::string($raw)") . ')',
        };

        return $c['nullable'] ? "($raw ?? null) === null ? null : $value" : $value;
    }

    /** @param array{type: string, nullable: bool} $c */
    private static function toDb(array $c, string $value): string
    {
        $converted = match ($c['type']) {
            'bool' => "\$d->bool($value)",
            'datetime' => "V::toDateTime($value)",
            'json' => "V::toJson($value)",
            'enum' => "{$value}->value",
            default => $value,
        };

        return $c['nullable'] && $converted !== $value ? "$value === null ? null : $converted" : $converted;
    }

    /** @param array{type: string, nullable: bool, enum?: string} $c */
    private static function setter(array $c, string $name): string
    {
        $check = match ($c['type']) {
            'string', 'text', 'decimal' => '\\is_string($v)',
            'int' => '\\is_int($v)',
            'float' => '\\is_float($v)',
            'bool' => '\\is_bool($v)',
            'datetime' => '$v instanceof \\DateTimeImmutable',
            'json' => '\\is_array($v)',
            default => '$v instanceof \\' . ($c['enum'] ?? ''),
        };
        if ($c['nullable']) {
            $check = "(\$v === null || $check)";
        }

        return "'$name' => \$e->$name = $check ? \$v : throw new \\InvalidArgumentException('Invalid value for $name.')";
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private static function id(string $class): string
    {
        return strtolower(str_replace('\\', '_', $class));
    }
}
