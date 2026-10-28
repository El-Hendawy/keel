<?php

declare(strict_types=1);

namespace Keel\Http\Idempotency;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class StoredResponse
{
    /**
     * @param array<string, list<string>> $headers
     * @param string|null $route the matched route pattern, so a replay is reported under the same route
     */
    public function __construct(public int $status, public array $headers, public string $body, public ?string $route = null)
    {
    }

    public static function from(ResponseInterface $response, ?string $route = null): self
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $contents = $body->getContents();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return new self($response->getStatusCode(), $headers, $contents, $route);
    }

    public function toResponse(): ResponseInterface
    {
        return new Response($this->status, $this->headers, $this->body);
    }

    public function encode(): string
    {
        return json_encode(['s' => $this->status, 'h' => $this->headers, 'b' => $this->body, 'r' => $this->route], \JSON_THROW_ON_ERROR);
    }

    public static function decode(string $json): self
    {
        /** @var array{s: int, h: array<string, list<string>>, b: string, r?: string|null} $data */
        $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);

        return new self($data['s'], $data['h'], $data['b'], $data['r'] ?? null);
    }
}
