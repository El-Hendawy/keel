<?php

declare(strict_types=1);

namespace Keel\Testing;

use Keel\Container\Attribute\Singleton;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The outbound HTTP client in tests. Nothing leaves the process: every request must
 * match a stub, and unmatched requests fail the test with a clear message.
 */
#[Singleton]
final class FakeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, pattern: string, response: ResponseInterface|\Closure(RequestInterface): ResponseInterface}> */
    private array $stubs = [];

    /** @var list<RequestInterface> */
    private array $sent = [];

    /**
     * Stubs a response for requests whose "METHOD url" matches $pattern (`*` is a wildcard).
     *
     * @param ResponseInterface|array<mixed>|\Closure(RequestInterface): ResponseInterface $response arrays become JSON
     */
    public function stub(string $method, string $urlPattern, ResponseInterface|array|\Closure $response, int $status = 200): self
    {
        if (\is_array($response)) {
            $response = new Response($status, ['Content-Type' => 'application/json'], json_encode($response, \JSON_THROW_ON_ERROR));
        }
        $this->stubs[] = ['method' => strtoupper($method), 'pattern' => $urlPattern, 'response' => $response];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        $url = (string) $request->getUri();
        foreach ($this->stubs as $stub) {
            if ($stub['method'] === $request->getMethod() && fnmatch($stub['pattern'], $url, \FNM_NOESCAPE)) {
                return $stub['response'] instanceof \Closure ? ($stub['response'])($request) : $stub['response'];
            }
        }

        throw new \LogicException("Unexpected outbound request in a test: {$request->getMethod()} $url. Stub it with FakeHttpClient::stub().");
    }

    /** @return list<RequestInterface> */
    public function sent(): array
    {
        return $this->sent;
    }
}
