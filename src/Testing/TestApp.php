<?php

declare(strict_types=1);

namespace Keel\Testing;

use Keel\Container\Container;
use Keel\Container\Scope;
use Keel\Runtime\Application;
use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The application booted in-process for tests (environment `test`).
 *
 * Requests go straight into the kernel: no server, no network, no sockets. The clock,
 * randomness, ids and outbound HTTP are fakes, reachable through typed accessors.
 *
 *     $app = TestApp::boot(__DIR__ . '/../app.php');
 *     $app->post('/orders', ['merchantId' => '...', 'items' => [...]])->assertStatus(201);
 */
final class TestApp
{
    private function __construct(public readonly Application $application)
    {
    }

    /** @param array<string, mixed> $config */
    public static function boot(string $appFile, array $config = []): self
    {
        return new self(Application::boot($appFile, ['env' => 'test', 'debug' => true, 'config' => $config, 'testing' => true]));
    }

    public function container(): Container
    {
        return $this->application->container();
    }

    public function clock(): FrozenClock
    {
        $clock = $this->container()->get(ClockInterface::class);
        \assert($clock instanceof FrozenClock);

        return $clock;
    }

    public function http(): FakeHttpClient
    {
        $client = $this->container()->get(ClientInterface::class);
        \assert($client instanceof FakeHttpClient);

        return $client;
    }

    /**
     * Runs $work inside a fresh scope, as a job or command would, and disposes it after.
     *
     * @template T
     * @param \Closure(Scope): T $work
     * @return T
     */
    public function inScope(\Closure $work): mixed
    {
        $scope = $this->container()->createScope();
        try {
            return $work($scope);
        } finally {
            $scope->dispose();
        }
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, null, $headers);
    }

    /**
     * @param array<mixed>|null $json
     * @param array<string, string> $headers
     */
    public function post(string $uri, ?array $json = null, array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $json, $headers);
    }

    /**
     * @param array<mixed>|null $json
     * @param array<string, string> $headers
     */
    public function put(string $uri, ?array $json = null, array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $json, $headers);
    }

    /**
     * @param array<mixed>|null $json
     * @param array<string, string> $headers
     */
    public function patch(string $uri, ?array $json = null, array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $json, $headers);
    }

    /** @param array<string, string> $headers */
    public function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, null, $headers);
    }

    /**
     * @param array<mixed>|string|null $body arrays are sent as JSON
     * @param array<string, string> $headers
     */
    public function request(string $method, string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        if (\is_array($body)) {
            $body = json_encode($body, \JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        }
        $request = new ServerRequest($method, 'http://localhost' . $uri, $headers, $body, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        return $this->send($request);
    }

    public function send(ServerRequestInterface $request): TestResponse
    {
        $response = $this->application->http()->handle($request);
        $this->application->afterRequest();

        return new TestResponse($response);
    }
}
