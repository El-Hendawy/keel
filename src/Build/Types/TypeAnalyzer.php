<?php

declare(strict_types=1);

namespace Keel\Build\Types;

use Keel\Build\ClassFinder;
use Keel\Build\SourceFile;
use Keel\Validation\Constraint;
use Keel\Validation\ListOf;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Reads input and output classes at build time.
 *
 * Inputs are described by their constructor (promoted or not); outputs by their public
 * properties. Array properties need an item type from `@var list<T>`, `@param list<T>`
 * or #[ListOf(T::class)]: an untyped array is a build error, not a runtime surprise.
 */
final class TypeAnalyzer
{
    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, SourceFile> */
    private array $files = [];

    /**
     * @param class-string $class
     * @return list<Field>
     */
    public function inputFields(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null || !$constructor->isPublic()) {
            $this->errors[] = "Input $class needs a public constructor; its parameters are the input fields.";

            return [];
        }

        $fields = [];
        foreach ($constructor->getParameters() as $parameter) {
            $property = $parameter->isPromoted() ? $reflection->getProperty($parameter->getName()) : null;
            $where = "$class::\${$parameter->getName()}";
            $type = $this->typeOf(
                $parameter->getType(),
                $this->docItemType($reflection, $parameter, $property),
                $this->listOf($parameter, $property),
                $where,
            );
            if ($type === null) {
                continue;
            }

            $fields[] = new Field(
                $parameter->getName(),
                $type,
                $this->constraints($parameter, $property, $type, $where),
                $parameter->isDefaultValueAvailable(),
                $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                $this->description($property),
            );
        }

        return $fields;
    }

    /**
     * @param class-string $class
     * @return list<Field>
     */
    public function outputFields(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $fields = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $parameter = null;
            if ($property->isPromoted()) {
                foreach ($reflection->getConstructor()?->getParameters() ?? [] as $candidate) {
                    if ($candidate->getName() === $property->getName()) {
                        $parameter = $candidate;
                    }
                }
            }
            $where = "$class::\${$property->getName()}";
            $type = $this->typeOf(
                $property->getType(),
                $this->docItemType($reflection, $parameter, $property),
                $this->listOf($parameter, $property),
                $where,
            );
            if ($type !== null) {
                $fields[] = new Field($property->getName(), $type, description: $this->description($property));
            }
        }

        return $fields;
    }

    /** Analyses a single type, such as a route parameter or a return type. */
    public function typeOf(?\ReflectionType $type, ?string $docItem, ?string $listOf, string $where): ?TypeInfo
    {
        if (!$type instanceof ReflectionNamedType) {
            $this->errors[] = "$where needs a single declared type (union, intersection and missing types are not supported in contracts).";

            return null;
        }

        $name = $type->getName();
        $nullable = $type->allowsNull() && $name !== 'mixed' && $name !== 'null';

        if ($name === 'array') {
            $itemName = $listOf ?? $docItem;
            if ($itemName === null) {
                $this->errors[] = "$where is an array without an item type. Add `@var list<Type>` or #[ListOf(Type::class)]. Arrays are not data contracts.";

                return null;
            }
            $item = $this->named($itemName, false, "$where item");

            return $item === null ? null : new TypeInfo(TypeInfo::LIST, $nullable, item: $item);
        }

        return $this->named($name, $nullable, $where);
    }

    private function named(string $name, bool $nullable, string $where): ?TypeInfo
    {
        $scalar = match ($name) {
            'string' => TypeInfo::STRING,
            'int' => TypeInfo::INT,
            'float' => TypeInfo::FLOAT,
            'bool' => TypeInfo::BOOL,
            default => null,
        };
        if ($scalar !== null) {
            return new TypeInfo($scalar, $nullable);
        }

        if (enum_exists($name)) {
            if (!is_subclass_of($name, \BackedEnum::class)) {
                $this->errors[] = "$where uses enum $name, which must be a backed enum to cross an API boundary.";

                return null;
            }

            return new TypeInfo(TypeInfo::ENUM, $nullable, $name);
        }

        if ($name === \DateTimeImmutable::class || $name === \DateTimeInterface::class) {
            return new TypeInfo(TypeInfo::DATETIME, $nullable, \DateTimeImmutable::class);
        }

        if (class_exists($name)) {
            return new TypeInfo(TypeInfo::OBJECT, $nullable, $name);
        }

        $this->errors[] = "$where has type $name, which is not supported in an input or output contract (use scalars, backed enums, DateTimeImmutable, typed classes or lists of those).";

        return null;
    }

    /** @return list<Constraint> */
    private function constraints(ReflectionParameter $parameter, ?ReflectionProperty $property, TypeInfo $type, string $where): array
    {
        $attributes = $parameter->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === [] && $property !== null) {
            $attributes = $property->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF);
        }

        $constraints = [];
        foreach ($attributes as $attribute) {
            $constraint = $attribute->newInstance();
            if (!$constraint->supports($type->kind)) {
                $this->errors[] = \sprintf('%s: #[%s] cannot be applied to a %s.', $where, (new ReflectionClass($constraint))->getShortName(), $type->kind);
                continue;
            }
            $constraints[] = $constraint;
        }

        return $constraints;
    }

    private function listOf(?ReflectionParameter $parameter, ?ReflectionProperty $property): ?string
    {
        $attribute = $parameter?->getAttributes(ListOf::class)[0] ?? $property?->getAttributes(ListOf::class)[0] ?? null;

        return $attribute?->newInstance()->type;
    }

    /** @param ReflectionClass<object> $class */
    private function docItemType(ReflectionClass $class, ?ReflectionParameter $parameter, ?ReflectionProperty $property): ?string
    {
        $raw = null;
        $propertyDoc = $property?->getDocComment();
        if (\is_string($propertyDoc) && preg_match('/@var\s+(\S+)/', $propertyDoc, $m) === 1) {
            $raw = $m[1];
        }
        if ($raw === null && $parameter !== null) {
            $functionDoc = $parameter->getDeclaringFunction()->getDocComment();
            $name = preg_quote($parameter->getName(), '/');
            if (\is_string($functionDoc) && preg_match('/@param\s+(\S+)\s+\$' . $name . '\b/', $functionDoc, $m) === 1) {
                $raw = $m[1];
            }
        }
        if ($raw === null) {
            return null;
        }

        $item = null;
        if (preg_match('/^(?:list|array|non-empty-list|non-empty-array)<(?:int\s*,\s*)?([^,>]+)>$/', $raw, $m) === 1) {
            $item = trim($m[1]);
        } elseif (preg_match('/^([^\[\]]+)\[\]$/', $raw, $m) === 1) {
            $item = $m[1];
        }
        if ($item === null) {
            return null;
        }
        if (\in_array($item, ['string', 'int', 'float', 'bool'], true)) {
            return $item;
        }

        return $this->sourceOf($class)?->resolve($item) ?? $item;
    }

    /** @param ReflectionClass<object> $class */
    private function sourceOf(ReflectionClass $class): ?SourceFile
    {
        $file = $class->getFileName();
        if ($file === false) {
            return null;
        }

        return $this->files[$file] ??= ClassFinder::parse($file);
    }

    private function description(?ReflectionProperty $property): ?string
    {
        $doc = $property?->getDocComment();
        if (!\is_string($doc)) {
            return null;
        }
        $lines = array_map(
            static fn (string $line): string => trim(ltrim(trim($line), '/*')),
            explode("\n", $doc),
        );
        $text = [];
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            $text[] = $line;
        }

        return $text === [] ? null : implode(' ', $text);
    }
}
