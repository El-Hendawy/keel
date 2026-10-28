<?php

declare(strict_types=1);

namespace Keel\Tests\Console;

use Composer\Autoload\ClassLoader;
use Keel\Console\ProjectGenerator;
use Keel\Runtime\Application;
use Keel\Runtime\BootFailed;
use Keel\Runtime\ProductionChecks;
use Keel\Testing\TestApp;
use PHPUnit\Framework\TestCase;

final class ScaffoldAndChecksTest extends TestCase
{
    public function testANewProjectWorksOutOfTheBox(): void
    {
        $directory = sys_get_temp_dir() . '/keel-tests/new-' . bin2hex(random_bytes(4));
        (new ProjectGenerator())->generate($directory, 'acme');
        $loader = array_values(ClassLoader::getRegisteredLoaders())[0];
        $loader->addPsr4('App\\', $directory . '/src/');

        TestApp::boot($directory . '/app.php')
            ->get('/hello/ada')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Hello, ada!');

        self::assertFileExists($directory . '/AGENTS.md');
        self::assertFileExists($directory . '/Caddyfile');
    }

    public function testProductionRefusesSingleNodeDefaults(): void
    {
        $app = Application::boot(__DIR__ . '/../Fixtures/Contracts/app.php', ['env' => 'prod-like', 'debug' => true]);
        $problems = ProductionChecks::problems($app);

        self::assertCount(5, $problems);
        self::assertStringContainsString('Debug is on in production', $problems[0]);
        self::assertStringContainsString('InMemoryRateLimitStore', implode("\n", $problems));
        self::assertStringContainsString('/metrics endpoint is public', implode("\n", $problems));
        self::assertStringContainsString('app.key is missing', implode("\n", $problems));
    }

    public function testProductionNeverBuildsOnTheFly(): void
    {
        $this->expectException(BootFailed::class);
        $this->expectExceptionMessage('Run `keel build --env=prod` during deployment.');
        Application::boot(__DIR__ . '/../Fixtures/Lifetimes/app.php', ['env' => 'prod']);
    }
}
