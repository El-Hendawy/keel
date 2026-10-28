<?php

declare(strict_types=1);

namespace Keel\Tests\Container;

use Keel\Build\BuildFailed;
use Keel\Build\Builder;
use Keel\Container\Exception\ConfigMissing;
use Keel\Container\Exception\ScopeRequired;
use Keel\Runtime\AppDefinition;
use Keel\Testing\TestApp;
use Keel\Tests\Fixtures\Contracts\AlphaPlugin;
use Keel\Tests\Fixtures\Contracts\BetaPlugin;
use Keel\Tests\Fixtures\Contracts\Connection;
use Keel\Tests\Fixtures\Contracts\Greeter;
use Keel\Tests\Fixtures\Contracts\PluginRegistry;
use Keel\Tests\Fixtures\Contracts\UnitOfWork;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures';

    public function testSingletonDependingOnScopedIsABuildError(): void
    {
        $definition = require self::FIXTURES . '/Lifetimes/app.php';
        \assert($definition instanceof AppDefinition);

        try {
            (new Builder($definition, 'test'))->build();
            self::fail('Expected the build to fail.');
        } catch (BuildFailed $e) {
            self::assertContains(
                'Singleton PriceCache cannot depend on Scoped CurrentTenant. Make it Scoped, or depend on something with a longer lifetime.',
                $e->errors,
            );
            self::assertContains(
                'Singleton ReportCache cannot depend on Transient TenantFormatter, which depends on Scoped CurrentTenant. Make it Scoped, or depend on something with a longer lifetime.',
                $e->errors,
            );
        }
    }

    public function testBindingsConfigFactoriesAndAll(): void
    {
        $app = TestApp::boot(self::FIXTURES . '/Contracts/app.php', ['greeting' => ['word' => 'Hi', 'excited' => 'true'], 'db' => ['dsn' => 'sqlite::memory:']]);
        $container = $app->container();

        self::assertSame('Hi, Ada!', $container->get(Greeter::class)->greet('Ada'));
        self::assertSame($container->get(Greeter::class), $container->get(Greeter::class), 'Singletons are shared.');
        self::assertSame('sqlite::memory:', $container->get(Connection::class)->dsn);

        $plugins = $container->get(PluginRegistry::class)->plugins;
        self::assertSame([AlphaPlugin::class, BetaPlugin::class], array_map(static fn (object $p): string => $p::class, $plugins));
    }

    public function testScopedServicesNeedAScopeAndAreDisposedWithIt(): void
    {
        $app = TestApp::boot(self::FIXTURES . '/Contracts/app.php', ['db' => ['dsn' => 'x']]);

        try {
            $app->container()->get(UnitOfWork::class);
            self::fail('Expected ScopeRequired.');
        } catch (ScopeRequired) {
        }

        UnitOfWork::$disposed = [];
        $first = $app->inScope(static function ($scope): string {
            self::assertSame($scope->get(UnitOfWork::class), $scope->get(UnitOfWork::class), 'One instance per scope.');

            return spl_object_hash($scope->get(UnitOfWork::class));
        });
        self::assertSame([$first], UnitOfWork::$disposed);
    }

    public function testEveryRequestGetsItsOwnScope(): void
    {
        $app = TestApp::boot(self::FIXTURES . '/Contracts/app.php', ['db' => ['dsn' => 'x']]);
        UnitOfWork::$disposed = [];

        $a = $app->get('/hello/ada')->assertStatus(200)->assertJsonPath('message', 'Hello, ada.');
        $b = $app->get('/hello/bob')->assertStatus(200);

        self::assertNotSame($a->get('unitOfWork'), $b->get('unitOfWork'));
        self::assertCount(2, UnitOfWork::$disposed);
    }

    public function testMissingRequiredConfigFailsWhenResolved(): void
    {
        $app = TestApp::boot(self::FIXTURES . '/Contracts/app.php');
        $this->expectException(ConfigMissing::class);
        $this->expectExceptionMessage('Configuration key "db.dsn" is required but not set.');
        $app->container()->get(Connection::class);
    }

    public function testModuleBoundariesAreEnforced(): void
    {
        $definition = require self::FIXTURES . '/Boundaries/app.php';
        \assert($definition instanceof AppDefinition);

        try {
            (new Builder($definition, 'test'))->build();
            self::fail('Expected the build to fail.');
        } catch (BuildFailed $e) {
            self::assertCount(1, $e->errors);
            self::assertStringContainsString('Module "orders" uses Keel\Tests\Fixtures\Boundaries\Billing\InvoiceRepository from module "billing"', $e->errors[0]);
            self::assertStringContainsString('does not list "billing" in dependsOn', $e->errors[0]);
        }
    }
}
