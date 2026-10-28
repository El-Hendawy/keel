<?php

declare(strict_types=1);

namespace Keel\Testing;

use Psr\Http\Message\ResponseInterface;

/** A response with assertions. Works with PHPUnit or on its own (failures throw AssertionFailed). */
final readonly class TestResponse
{
    public function __construct(public ResponseInterface $response)
    {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        $body = $this->response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }

    /**
     * The decoded body, from JSON or TOON depending on the response's Content-Type.
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        $decoded = str_starts_with($this->header('Content-Type'), \Keel\Http\Toon\Toon::MEDIA_TYPE)
            ? \Keel\Http\Toon\Toon::decode($this->body())
            : json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /** Reads a value by dotted path, e.g. `errors.0.path`. */
    public function get(string $path): mixed
    {
        $value = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                throw new AssertionFailed("Path \"$path\" not found in response: " . $this->body());
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function assertStatus(int $expected): self
    {
        self::check($this->status() === $expected, "Expected status $expected, got {$this->status()}. Body: " . $this->body());

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        $actual = $this->get($path);
        self::check($actual === $expected, \sprintf('Expected %s to be %s, got %s.', $path, var_export($expected, true), var_export($actual, true)));

        return $this;
    }

    /** Asserts that the array at a dotted path (e.g. `data`) has $expected items. */
    public function assertJsonCount(string $path, int $expected): self
    {
        $actual = $this->get($path);
        self::check(\is_array($actual) && \count($actual) === $expected, \sprintf('Expected %s to have %d item(s), got %s.', $path, $expected, \is_array($actual) ? \count($actual) : get_debug_type($actual)));

        return $this;
    }

    public function assertHeader(string $name, ?string $expected = null): self
    {
        self::check($this->response->hasHeader($name), "Expected header $name to be present.");
        if ($expected !== null) {
            self::check($this->header($name) === $expected, "Expected header $name to be \"$expected\", got \"{$this->header($name)}\".");
        }

        return $this;
    }

    /** Reports through PHPUnit when it is running, so assertions are counted; throws otherwise. */
    private static function check(bool $condition, string $message): void
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertTrue($condition, $message);

            return;
        }
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }
}
