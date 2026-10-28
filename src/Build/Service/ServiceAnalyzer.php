<?php

declare(strict_types=1);

namespace Keel\Build\Service;

use Keel\Container\Attribute\All;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Factory;
use Keel\Container\Attribute\LifetimeAttribute;
use Keel\Container\Lifetime;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

/** Turns classes and factory methods into service definitions. Runs at build time only. */
final class ServiceAnalyzer
{
    /** @var list<string> */
    public array $errors = [];

    /** @param ReflectionClass<object> $class */
    public static function declaredLifetime(ReflectionClass $class): ?Lifetime
    {
        $attributes = $class->getAttributes(LifetimeAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->lifetime();
    }

    /** @param ReflectionClass<object> $class */
    public function fromClass(ReflectionClass $class, Lifetime $lifetime, ?string $id = null): ?ServiceDefinition
    {
        if (!$class->isInstantiable()) {
            $this->errors[] = \sprintf('%s is registered as a service but cannot be instantiated (abstract, interface or private constructor).', $class->getName());

            return null;
        }
        $constructor = $class->getConstructor();
        $arguments = $constructor === null ? [] : $this->arguments($constructor, $class->getName());

        return new ServiceDefinition(
            $id ?? $class->getName(),
            $lifetime,
            $class->getName(),
            $arguments,
            origin: $class->getName(),
        );
    }

    /**
     * @param ReflectionClass<object> $owner
     * @return list<ServiceDefinition>
     */
    public function factoriesOf(ReflectionClass $owner): array
    {
        $definitions = [];
        foreach ($owner->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(Factory::class);
            if ($attributes === []) {
                continue;
            }
            $where = $owner->getName() . '::' . $method->getName() . '()';
            $return = $method->getReturnType();
            if (!$return instanceof ReflectionNamedType || $return->isBuiltin() || $return->allowsNull()) {
                $this->errors[] = "$where is a #[Factory] and must declare a single, non-nullable class return type.";
                continue;
            }
            if ($method->isStatic()) {
                $this->errors[] = "$where is a #[Factory] and must not be static.";
                continue;
            }
            $definitions[] = new ServiceDefinition(
                $return->getName(),
                $attributes[0]->newInstance()->lifetime,
                null,
                $this->arguments($method, $where),
                factory: [$owner->getName(), $method->getName()],
                origin: $where,
            );
        }

        return $definitions;
    }

    /** @return list<Argument> */
    public function arguments(ReflectionFunctionAbstract $function, string $where): array
    {
        $arguments = [];
        foreach ($function->getParameters() as $parameter) {
            $argument = $this->argument($parameter, $where);
            if ($argument !== null) {
                $arguments[] = $argument;
            }
        }

        return $arguments;
    }

    /** Array config parameters documented as list<string> get a validated list. */
    private static function isStringList(ReflectionParameter $parameter): bool
    {
        $doc = $parameter->getDeclaringFunction()->getDocComment();
        $name = preg_quote($parameter->getName(), '/');

        return \is_string($doc) && preg_match('/@param\s+list<string>\s+\$' . $name . '\b/', $doc) === 1;
    }

    private function argument(ReflectionParameter $parameter, string $where): ?Argument
    {
        $name = $parameter->getName();
        $at = "\$$name in $where";
        $type = $parameter->getType();
        $hasDefault = $parameter->isDefaultValueAvailable();
        $default = $hasDefault ? $parameter->getDefaultValue() : null;

        if ($parameter->isVariadic()) {
            $this->errors[] = "Variadic parameter $at cannot be autowired.";

            return null;
        }

        $config = $parameter->getAttributes(Config::class)[0] ?? null;
        $all = $parameter->getAttributes(All::class)[0] ?? null;

        if ($all !== null) {
            if (!$type instanceof ReflectionNamedType || !\in_array($type->getName(), ['array', 'iterable'], true)) {
                $this->errors[] = "$at uses #[All] and must be typed as array.";

                return null;
            }

            return new Argument($name, Argument::ALL, $all->newInstance()->type);
        }

        if (!$type instanceof ReflectionNamedType) {
            $this->errors[] = "$at must have a single named type to be autowired (union and intersection types are not supported).";

            return null;
        }

        if ($config !== null) {
            $scalar = $type->getName();
            if (!\in_array($scalar, ['string', 'int', 'float', 'bool', 'array'], true)) {
                $this->errors[] = "$at uses #[Config] and must be string, int, float, bool or array.";

                return null;
            }

            if ($scalar === 'array' && self::isStringList($parameter)) {
                $scalar = 'list<string>';
            }

            return new Argument(
                $name,
                Argument::CONFIG,
                $scalar,
                $hasDefault,
                $default,
                $type->allowsNull(),
                $config->newInstance()->key,
            );
        }

        if (!$type->isBuiltin()) {
            return new Argument($name, Argument::SERVICE, $type->getName(), $hasDefault, $default, $type->allowsNull());
        }

        if ($hasDefault) {
            return new Argument($name, Argument::VALUE, $type->getName(), true, $default);
        }

        $this->errors[] = "$at is a {$type->getName()} without a default. Add #[Config('some.key')] or a default value.";

        return null;
    }
}
