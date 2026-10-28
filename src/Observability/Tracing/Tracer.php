<?php

declare(strict_types=1);

namespace Keel\Observability\Tracing;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Support\Random;

/**
 * Creates spans and keeps track of the active one.
 *
 * The active span is tracked per fiber, so concurrent tasks started with await_all()
 * each nest their spans correctly under the span that started them.
 * Finished spans are buffered and exported by flush(), which the runtime calls after
 * the response has been sent.
 */
#[Singleton]
final class Tracer
{
    private ?Span $rootActive = null;

    /** @var \WeakMap<object, Span> active span per fiber */
    private \WeakMap $fiberActive;

    private static ?int $epochOffsetNs = null;

    /** @var list<Span> */
    private array $finished = [];

    public function __construct(
        private readonly SpanExporter $exporter,
        private readonly Random $random,
        #[Config('tracing.sample_ratio')] private readonly float $sampleRatio = 1.0,
    ) {
        $this->fiberActive = new \WeakMap();
    }

    /** @param array<string, scalar|null> $attributes */
    public function startSpan(string $name, array $attributes = [], string $kind = Span::KIND_INTERNAL, ?string $traceparent = null): Span
    {
        $parent = $this->active();
        $remote = $traceparent !== null ? self::parseTraceparent($traceparent) : null;

        if ($remote !== null) {
            [$traceId, $parentId, $sampled] = $remote;
        } elseif ($parent !== null) {
            [$traceId, $parentId, $sampled] = [$parent->traceId, $parent->spanId, $parent->sampled];
        } else {
            $traceId = bin2hex($this->random->bytes(16));
            $parentId = null;
            $sampled = $this->sampleRatio >= 1.0 || ($this->sampleRatio > 0.0 && $this->random->int(0, 999_999) < $this->sampleRatio * 1_000_000);
        }

        $span = new Span($name, $traceId, bin2hex($this->random->bytes(8)), $parentId, $kind, $sampled, self::nowNs(), $attributes, $this);
        $this->activate($span);

        return $span;
    }

    /**
     * Runs $operation inside a span that is ended, and marked as failed on exception, automatically.
     *
     * @template T
     * @param \Closure(Span): T $operation
     * @param array<string, scalar|null> $attributes
     * @return T
     */
    public function trace(string $name, \Closure $operation, array $attributes = [], string $kind = Span::KIND_INTERNAL): mixed
    {
        $span = $this->startSpan($name, $attributes, $kind);
        try {
            return $operation($span);
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
        }
    }

    public function active(): ?Span
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fiberActive[$fiber])) {
            return $this->fiberActive[$fiber];
        }

        return $this->rootActive;
    }

    /** @internal called by Span::end() */
    public function finish(Span $span): void
    {
        // Restore the parent as the active span.
        $fiber = \Fiber::getCurrent();
        $active = $this->active();
        if ($active === $span) {
            $parent = null;
            foreach (array_reverse($this->openSpans) as $candidate) {
                if ($candidate->spanId === $span->parentSpanId) {
                    $parent = $candidate;
                    break;
                }
            }
            if ($fiber !== null && isset($this->fiberActive[$fiber])) {
                if ($parent !== null) {
                    $this->fiberActive[$fiber] = $parent;
                } else {
                    unset($this->fiberActive[$fiber]);
                }
            } else {
                $this->rootActive = $parent;
            }
        }
        $this->openSpans = array_values(array_filter($this->openSpans, static fn (Span $s): bool => $s !== $span));

        if ($span->sampled) {
            $this->finished[] = $span;
        }
    }

    /** @var list<Span> started and not yet ended */
    private array $openSpans = [];

    private function activate(Span $span): void
    {
        $this->openSpans[] = $span;
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            $this->fiberActive[$fiber] = $span;
        } else {
            $this->rootActive = $span;
        }
    }

    /** Exports buffered spans and clears per-request state. Never throws. */
    public function flush(): void
    {
        $spans = $this->finished;
        $this->finished = [];
        $this->openSpans = [];
        $this->rootActive = null;
        try {
            $this->exporter->export($spans);
        } catch (\Throwable) {
            // Telemetry must never take the service down.
        }
    }

    /** @return array{0: string, 1: string, 2: bool}|null */
    public static function parseTraceparent(string $header): ?array
    {
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', trim($header), $m) !== 1) {
            return null;
        }
        if ($m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return null;
        }

        return [$m[1], $m[2], (hexdec($m[3]) & 1) === 1];
    }

    /** Wall-clock nanoseconds, monotonic within the process. */
    public static function nowNs(): int
    {
        self::$epochOffsetNs ??= (int) (microtime(true) * 1e9) - hrtime(true);

        return self::$epochOffsetNs + hrtime(true);
    }
}
