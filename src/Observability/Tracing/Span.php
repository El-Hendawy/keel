<?php

declare(strict_types=1);

namespace Keel\Observability\Tracing;

/** One timed operation in a trace. End it exactly once; use Tracer::trace() to make that automatic. */
final class Span
{
    public const string KIND_INTERNAL = 'internal';
    public const string KIND_SERVER = 'server';
    public const string KIND_CLIENT = 'client';
    public const string KIND_PRODUCER = 'producer';
    public const string KIND_CONSUMER = 'consumer';

    public ?int $endNs = null;
    public bool $error = false;
    public string $statusMessage = '';

    /** @var list<array{name: string, timeNs: int, attributes: array<string, scalar>}> */
    public array $events = [];

    /** @param array<string, scalar|null> $attributes */
    public function __construct(
        public string $name,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $kind,
        public readonly bool $sampled,
        public readonly int $startNs,
        public array $attributes = [],
        private readonly ?Tracer $tracer = null,
    ) {
    }

    public function setAttribute(string $key, string|int|float|bool|null $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function recordException(\Throwable $e): self
    {
        $this->error = true;
        $this->statusMessage = $e->getMessage();
        $this->events[] = [
            'name' => 'exception',
            'timeNs' => Tracer::nowNs(),
            'attributes' => [
                'exception.type' => $e::class,
                'exception.message' => $e->getMessage(),
            ],
        ];

        return $this;
    }

    public function end(): void
    {
        if ($this->endNs !== null) {
            return;
        }
        $this->endNs = Tracer::nowNs();
        $this->tracer?->finish($this);
    }

    /** W3C traceparent header value for propagating this span to downstream services. */
    public function traceparent(): string
    {
        return \sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $this->sampled ? '01' : '00');
    }
}
