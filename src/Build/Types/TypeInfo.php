<?php

declare(strict_types=1);

namespace Keel\Build\Types;

/** A data type the compiler knows how to parse, validate, serialize and document. */
final readonly class TypeInfo
{
    public const string STRING = 'string';
    public const string INT = 'int';
    public const string FLOAT = 'float';
    public const string BOOL = 'bool';
    public const string ENUM = 'enum';
    public const string DATETIME = 'datetime';
    public const string OBJECT = 'object';
    public const string LIST = 'list';

    /**
     * @param self::* $kind
     * @param class-string|null $class for enum, datetime and object kinds
     */
    public function __construct(
        public string $kind,
        public bool $nullable = false,
        public ?string $class = null,
        public ?TypeInfo $item = null,
    ) {
    }

    /** @return class-string */
    public function classOrFail(): string
    {
        return $this->class ?? throw new \LogicException("Type {$this->kind} has no class.");
    }

    /** @return list<int|string> */
    public static function enumValues(string $class): array
    {
        if (!is_subclass_of($class, \BackedEnum::class)) {
            return [];
        }

        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $class::cases());
    }

    public static function isIntBacked(string $class): bool
    {
        return \is_int(self::enumValues($class)[0] ?? '');
    }

    public function isScalar(): bool
    {
        return \in_array($this->kind, [self::STRING, self::INT, self::FLOAT, self::BOOL], true);
    }

    public function describe(): string
    {
        $base = match ($this->kind) {
            self::LIST => 'list<' . ($this->item?->describe() ?? 'mixed') . '>',
            self::ENUM, self::OBJECT, self::DATETIME => (string) $this->class,
            default => $this->kind,
        };

        return ($this->nullable ? '?' : '') . $base;
    }
}
