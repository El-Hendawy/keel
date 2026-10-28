<?php

declare(strict_types=1);

namespace Keel\Console;

/**
 * Scaffolds a new project that is production-shaped from the first commit:
 * worker entry point, typed endpoint and test, PHPStan at max, agent instructions.
 */
final class ProjectGenerator
{
    public function generate(string $directory, string $name): void
    {
        if (is_dir($directory) && (scandir($directory) ?: []) !== ['.', '..']) {
            throw new \RuntimeException("$directory exists and is not empty.");
        }
        $namespace = 'App';
        $package = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));

        foreach ($this->files($name, $package, $namespace) as $path => $contents) {
            $file = $directory . '/' . $path;
            if (!is_dir(\dirname($file)) && !mkdir(\dirname($file), 0o775, true) && !is_dir(\dirname($file))) {
                throw new \RuntimeException('Cannot create ' . \dirname($file));
            }
            file_put_contents($file, $contents);
        }
    }

    /** @return array<string, string> */
    private function files(string $name, string $package, string $namespace): array
    {
        $composer = [
            'name' => "app/$package",
            'type' => 'project',
            // The repository is listed so projects install before the first tagged release reaches Packagist.
            'repositories' => [['type' => 'vcs', 'url' => 'https://github.com/El-Hendawy/keel']],
            'require' => ['php' => '>=8.3', 'el-hendawy/keel' => '^0.2@dev'],
            'require-dev' => ['phpstan/phpstan' => '^2.0', 'phpunit/phpunit' => '^11.0'],
            'autoload' => ['psr-4' => ["$namespace\\" => 'src/']],
            'autoload-dev' => ['psr-4' => ["$namespace\\Tests\\" => 'tests/']],
            'scripts' => [
                'build' => 'keel build --env=prod',
                'test' => 'phpunit',
                'analyse' => 'phpstan analyse',
            ],
            'config' => ['sort-packages' => true],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        return [
            'composer.json' => json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
            'app.php' => <<<PHP
                <?php

                declare(strict_types=1);

                use Keel\\Runtime\\AppDefinition;

                // The shape of the application, compiled by `keel build`. Runtime values live in config.php.
                return AppDefinition::create(__DIR__, '$name')
                    ->scan('src')
                    ->config('config.php');

                PHP,
            'config.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                /** @var Keel\Runtime\Environment $env */
                return [
                    // 32+ random bytes from a secret; `keel key:generate` prints one. Required in production.
                    'app' => ['key' => getenv('APP_KEY') ?: ''],
                    'log' => ['level' => $env->isTest() ? 'warning' : 'info'],
                    'metrics' => ['token' => getenv('METRICS_TOKEN') ?: ''],
                ];

                PHP,
            'public/index.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                require __DIR__ . '/../vendor/autoload.php';

                Keel\Runtime\Runtime::run(__DIR__ . '/../app.php');

                PHP,
            "src/Greeting/GreetingModule.php" => <<<PHP
                <?php

                declare(strict_types=1);

                namespace $namespace\\Greeting;

                use Keel\\Module\\Module;

                #[Module(name: 'greeting')]
                final class GreetingModule
                {
                }

                PHP,
            "src/Greeting/SayHello.php" => <<<PHP
                <?php

                declare(strict_types=1);

                namespace $namespace\\Greeting;

                use Keel\\Routing\\Attribute\\Get;
                use Psr\\Clock\\ClockInterface;

                /** Greets someone by name. */
                #[Get('/hello/{name}', name: 'greeting.hello')]
                final class SayHello
                {
                    public function __invoke(string \$name, ClockInterface \$clock): Greeting
                    {
                        return new Greeting("Hello, \$name!", \$clock->now());
                    }
                }

                PHP,
            "src/Greeting/Greeting.php" => <<<PHP
                <?php

                declare(strict_types=1);

                namespace $namespace\\Greeting;

                final readonly class Greeting
                {
                    public function __construct(
                        public string \$message,
                        public \\DateTimeImmutable \$at,
                    ) {
                    }
                }

                PHP,
            'tests/SayHelloTest.php' => <<<PHP
                <?php

                declare(strict_types=1);

                namespace $namespace\\Tests;

                use Keel\\Testing\\TestApp;
                use PHPUnit\\Framework\\TestCase;

                final class SayHelloTest extends TestCase
                {
                    public function testItGreets(): void
                    {
                        \$app = TestApp::boot(__DIR__ . '/../app.php');

                        \$app->get('/hello/ada')
                            ->assertStatus(200)
                            ->assertJsonPath('message', 'Hello, ada!')
                            ->assertJsonPath('at', '2026-01-01T00:00:00.000+00:00'); // the test clock is frozen
                    }
                }

                PHP,
            'phpunit.xml.dist' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <phpunit bootstrap="vendor/autoload.php" colors="true" failOnRisky="true" failOnWarning="true" cacheDirectory="var/phpunit">
                    <testsuites>
                        <testsuite name="app">
                            <directory>tests</directory>
                        </testsuite>
                    </testsuites>
                </phpunit>

                XML,
            'phpstan.neon.dist' => <<<'NEON'
                parameters:
                    level: max
                    paths:
                        - src
                        - tests

                NEON,
            'Caddyfile' => <<<'CADDY'
                {
                	frankenphp {
                		worker {
                			file ./public/index.php
                			env KEEL_ENV prod
                		}
                	}
                }

                :8080 {
                	root * public/
                	php_server
                }

                CADDY,
            '.gitignore' => "/vendor/\n/var/\n",
            'AGENTS.md' => <<<MD
                # Working on $name

                This is a Keel application. Keel compiles the application at build time; nothing is
                discovered at runtime, so everything an agent needs is in the source and in
                `vendor/bin/keel inspect` (routes, services and their lifetimes, modules, config keys).

                ## Rules

                - One endpoint = one invokable class with a route attribute, in the module it belongs to.
                - Inputs are `final readonly` classes; validation attributes go on constructor parameters.
                - Outputs are `final readonly` classes with public properties. Never return arrays.
                - Every service declares `#[Singleton]`, `#[Scoped]` or `#[Transient]`. Per-request state is Scoped.
                - Use constructor injection. No facades, no service lookup by string, no static state.
                - Inject `ClockInterface`, `IdGenerator`, `Random` and `ClientInterface` instead of calling
                  `time()`, `uniqid()`, `random_int()` or curl, so tests stay deterministic.
                - Cross-module calls go through classes listed in the target module's `exports`.

                ## Checks (all must pass)

                ```bash
                vendor/bin/keel build
                vendor/bin/phpstan analyse
                vendor/bin/phpunit
                ```

                MD,
        ];
    }
}
