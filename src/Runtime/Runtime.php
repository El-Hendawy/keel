<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Http\ResponseEmitter;
use Keel\Http\ServerRequestFactory;

/**
 * Entry points. public/index.php and worker.php are one line each:
 *
 *     Keel\Runtime\Runtime::run(__DIR__ . '/../app.php');
 *
 * The runtime is detected: FrankenPHP worker mode, RoadRunner, or classic
 * request-per-process (FPM, CGI, the built-in server) as the fallback.
 */
final class Runtime
{
    public static function run(string $appFile): int
    {
        if (\function_exists('frankenphp_handle_request') && \PHP_SAPI !== 'cli-server') {
            return self::frankenphp($appFile, WorkerPolicy::fromEnvironment());
        }
        if (getenv('RR_MODE') === 'http') {
            return self::roadrunner($appFile, WorkerPolicy::fromEnvironment());
        }

        return self::classic($appFile);
    }

    /** One request per process (FPM, CGI, php -S). The fallback, never the design centre. */
    public static function classic(string $appFile): int
    {
        $app = Application::boot($appFile);
        $request = ServerRequestFactory::fromGlobals();
        $response = $app->http()->handle($request);
        ResponseEmitter::emit($response, $request->getMethod() !== 'HEAD');
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $app->shutdown();

        return 0;
    }

    /** FrankenPHP worker mode: boot once, then handle requests in a loop. */
    public static function frankenphp(string $appFile, WorkerPolicy $policy): int
    {
        $app = Application::boot($appFile);
        $kernel = $app->http();
        $policy->listenForSignals($app->drain(...));
        ignore_user_abort(true);

        $handler = static function () use ($kernel): void {
            $request = ServerRequestFactory::fromGlobals();
            ResponseEmitter::emit($kernel->handle($request), $request->getMethod() !== 'HEAD');
        };

        $handled = 0;
        do {
            $keepRunning = frankenphp_handle_request($handler);
            $handled++;
            $app->afterRequest();
            gc_collect_cycles();
        } while ($keepRunning && !$policy->shouldStop($handled));

        $app->shutdown();

        return 0;
    }

    /** RoadRunner: requires spiral/roadrunner-http. */
    public static function roadrunner(string $appFile, WorkerPolicy $policy): int
    {
        if (!class_exists(\Spiral\RoadRunner\Http\PSR7Worker::class)) {
            throw new BootFailed('RoadRunner mode needs spiral/roadrunner-http: composer require spiral/roadrunner-http');
        }
        $app = Application::boot($appFile);
        $kernel = $app->http();
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $worker = new \Spiral\RoadRunner\Http\PSR7Worker(\Spiral\RoadRunner\Worker::create(), $factory, $factory, $factory);
        $policy->listenForSignals($app->drain(...));

        $handled = 0;
        while (!$policy->shouldStop($handled) && ($request = $worker->waitRequest()) !== null) {
            try {
                $worker->respond($kernel->handle($request));
            } catch (\Throwable $e) {
                $worker->getWorker()->error((string) $e);
            }
            $handled++;
            $app->afterRequest();
            gc_collect_cycles();
        }

        $app->shutdown();

        return 0;
    }
}
