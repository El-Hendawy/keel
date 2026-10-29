<?php

declare(strict_types=1);

namespace Keel\Tests\Vector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * stubs/keel_vector.stub.php describes the API; whichever implementation is loaded (the extension or
 * the pure-PHP one) must have exactly its public methods, with the same names, parameters and
 * static-ness. Run under both to keep the two in step.
 */
final class StubTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, array{bool, list<string>, int}>}> */
    public static function classes(): iterable
    {
        $source = (string) file_get_contents(__DIR__ . '/../../stubs/keel_vector.stub.php');
        preg_match_all('/^(?:final )?class (\w+)[^{\n]*\n?\{(?:\}|(.*?)^\})/ms', $source, $classes, PREG_SET_ORDER);
        foreach ($classes as $match) {
            [$name, $body] = [$match[1], $match[2] ?? ''];
            $methods = [];
            preg_match_all('/public (static )?function (\w+)\(([^)]*)\)/', $body, $found, PREG_SET_ORDER);
            foreach ($found as [, $static, $method, $parameters]) {
                preg_match_all('/\$(\w+)( =)?/', $parameters, $params, PREG_SET_ORDER);
                $required = \count(array_filter($params, static fn (array $p): bool => !isset($p[2])));
                $methods[$method] = [$static !== '', array_column($params, 1), $required];
            }
            yield $name => ["Keel\\Vector\\$name", $methods];
        }
    }

    /**
     * @param class-string $class
     * @param array<string, array{bool, list<string>, int}> $expected
     */
    #[DataProvider('classes')]
    public function testTheLoadedClassMatchesTheStub(string $class, array $expected): void
    {
        $actual = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $internal = str_contains((string) $method->getDocComment(), '@internal');
            $inherited = $method->getDeclaringClass()->getName() !== $class;
            $hiddenConstructor = $method->getName() === '__construct' && !isset($expected['__construct']);
            if ($internal || $inherited || $hiddenConstructor) {
                continue;
            }
            $actual[$method->getName()] = [
                $method->isStatic(),
                array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()),
                $method->getNumberOfRequiredParameters(),
            ];
        }
        ksort($actual);
        ksort($expected);
        self::assertSame($expected, $actual, $class . (\extension_loaded('keel_vector') ? ' (extension)' : ' (pure PHP)'));
    }
}
