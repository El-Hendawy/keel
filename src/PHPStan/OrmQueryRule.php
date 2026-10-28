<?php

declare(strict_types=1);

namespace Keel\PHPStan;

use Keel\Orm\Attribute\BelongsTo;
use Keel\Orm\Attribute\BelongsToMany;
use Keel\Orm\Attribute\HasMany;
use Keel\Orm\Attribute\HasOne;
use Keel\Orm\Criteria;
use Keel\Orm\Many;
use Keel\Orm\One;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks ORM queries during static analysis, so they fail before they run:
 *
 *     $orm->query(Post::class)->where('titel', 'x');            // Post has no column property "titel"
 *     $orm->query(Post::class)->where('status', 'published');   // status expects PostStatus, 'published' given
 *     $orm->query(Post::class)->where('rating', '=>', 3);       // unsupported operator
 *     $orm->query(Post::class)->with('comment');                // Post has no relation "comment"
 *
 * Enable it with `includes: [vendor/el-hendawy/keel/extension.neon]` (automatic with
 * phpstan/extension-installer).
 *
 * @implements Rule<MethodCall>
 */
final class OrmQueryRule implements Rule
{
    private const array COLUMN_METHODS = ['where', 'orWhere', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween', 'orderBy', 'latest', 'pluck', 'sum', 'avg', 'min', 'max'];
    private const array RELATION_METHODS = ['with', 'whereHas', 'whereDoesntHave'];
    private const array OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    public function __construct(private readonly ReflectionProvider $reflection)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }
        $method = $node->name->toString();
        if (!\in_array($method, [...self::COLUMN_METHODS, ...self::RELATION_METHODS, 'update'], true)) {
            return [];
        }
        $entity = $scope->getType($node->var)->getTemplateType(Criteria::class, 'T');
        $classes = $entity->getObjectClassNames();
        if (\count($classes) !== 1 || !$this->reflection->hasClass($classes[0])) {
            return [];
        }
        $class = $classes[0];
        [$columns, $relations] = $this->mapping($class);
        if ($columns === []) {
            return [];
        }
        $short = self::short($class);
        $args = $node->getArgs();
        $errors = [];

        if (\in_array($method, self::RELATION_METHODS, true)) {
            $names = $method === 'with' ? $args : \array_slice($args, 0, 1);
            foreach ($names as $arg) {
                foreach ($scope->getType($arg->value)->getConstantStrings() as $path) {
                    $error = $this->relationPath($class, $path->getValue());
                    if ($error !== null) {
                        $errors[] = RuleErrorBuilder::message($error)->identifier('keel.orm.relation')->build();
                    }
                }
            }

            return $errors;
        }

        if ($method === 'update') {
            foreach ($args === [] ? [] : $scope->getType($args[0]->value)->getConstantArrays() as $array) {
                foreach ($array->getKeyTypes() as $i => $key) {
                    foreach ($key->getConstantStrings() as $name) {
                        $errors = [...$errors, ...$this->checkValue($short, $columns, $name->getValue(), $array->getValueTypes()[$i])];
                    }
                }
            }

            return $errors;
        }

        if ($args === [] || !$args[0]->value instanceof Node\Scalar\String_) {
            return []; // a closure group, or a dynamic name
        }
        $property = $args[0]->value->value;
        if (!isset($columns[$property])) {
            return [RuleErrorBuilder::message(\sprintf('%s has no column property "%s". Columns: %s.', $short, $property, implode(', ', array_keys($columns))))->identifier('keel.orm.column')->build()];
        }
        if ($method === 'where' || $method === 'orWhere') {
            if (\count($args) === 3) {
                foreach ($scope->getType($args[1]->value)->getConstantStrings() as $operator) {
                    if (!\in_array(strtolower($operator->getValue()), self::OPERATORS, true)) {
                        $errors[] = RuleErrorBuilder::message(\sprintf('Unsupported operator "%s". Use one of: %s.', $operator->getValue(), implode(', ', self::OPERATORS)))->identifier('keel.orm.operator')->build();
                    }
                }
                $operators = $scope->getType($args[1]->value)->getConstantStrings();
                $isLike = $operators !== [] && str_contains(strtolower($operators[0]->getValue()), 'like');
                if (!$isLike) {
                    $errors = [...$errors, ...$this->checkValue($short, $columns, $property, $scope->getType($args[2]->value))];
                }
            } elseif (\count($args) === 2) {
                $errors = [...$errors, ...$this->checkValue($short, $columns, $property, $scope->getType($args[1]->value))];
            }
        }
        if (($method === 'whereIn' || $method === 'whereNotIn') && isset($args[1])) {
            $errors = [...$errors, ...$this->checkValue($short, $columns, $property, $scope->getType($args[1]->value)->getIterableValueType())];
        }

        return $errors;
    }

    /**
     * @param array<string, Type> $columns
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkValue(string $entity, array $columns, string $property, Type $value): array
    {
        $expected = $columns[$property] ?? null;
        if ($expected === null) {
            return [RuleErrorBuilder::message(\sprintf('%s has no column property "%s". Columns: %s.', $entity, $property, implode(', ', array_keys($columns))))->identifier('keel.orm.column')->build()];
        }
        if (TypeCombinator::addNull($expected)->accepts($value, true)->no()) {
            return [RuleErrorBuilder::message(\sprintf('%s::$%s expects %s, %s given.', $entity, $property, $expected->describe(VerbosityLevel::typeOnly()), $value->describe(VerbosityLevel::value())))->identifier('keel.orm.value')->build()];
        }

        return [];
    }

    private function relationPath(string $class, string $path): ?string
    {
        foreach (explode('.', $path) as $segment) {
            [, $relations] = $this->mapping($class);
            $target = $relations[$segment] ?? null;
            if ($target === null) {
                return \sprintf('%s has no relation "%s". Relations: %s.', self::short($class), $segment, $relations === [] ? 'none' : implode(', ', array_keys($relations)));
            }
            $class = $target;
        }

        return null;
    }

    private static function short(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }

    /**
     * Column properties with their declared types, and relations with their targets.
     *
     * @return array{0: array<string, Type>, 1: array<string, class-string>}
     */
    private function mapping(string $class): array
    {
        if (!$this->reflection->hasClass($class)) {
            return [[], []];
        }
        $native = $this->reflection->getClass($class)->getNativeReflection();
        if ($native->getAttributes(\Keel\Orm\Attribute\Entity::class) === []) {
            return [[], []];
        }
        $columns = [];
        $relations = [];
        foreach ($native->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isPromoted()) {
                continue;
            }
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            if ($typeName === Many::class || $typeName === One::class) {
                foreach ([HasMany::class, HasOne::class, BelongsTo::class, BelongsToMany::class] as $attribute) {
                    $found = $parameter->getAttributes($attribute)[0] ?? null;
                    if ($found !== null) {
                        $target = $found->getArguments()['target'] ?? $found->getArguments()[0] ?? null;
                        if (\is_string($target) && class_exists($target)) {
                            $relations[$parameter->getName()] = $target;
                        }
                    }
                }
                continue;
            }
            $columns[$parameter->getName()] = $this->reflection->getClass($class)->getNativeProperty($parameter->getName())->getNativeType();
        }

        return [$columns, $relations];
    }
}
