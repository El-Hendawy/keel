<?php

declare(strict_types=1);

namespace Keel\Observability\Logging;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Container\Disposable;
use Keel\Observability\Tracing\Tracer;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Structured logs: one JSON object per line, with the active trace and span ids,
 * ready for any log pipeline. Writes to stderr by default (12-factor).
 */
#[Singleton]
final class JsonLogger extends AbstractLogger implements Disposable
{
    private const array LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var resource|null */
    private $stream = null;

    private readonly int $threshold;

    public function __construct(
        private readonly Tracer $tracer,
        #[Config('log.stream')] private readonly string $target = 'php://stderr',
        #[Config('log.level')] string $level = LogLevel::INFO,
        #[Config('app.name')] private readonly string $service = 'keel-app',
    ) {
        $this->threshold = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown log level \"$level\".");
    }

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = \is_string($level) ? $level : LogLevel::INFO;
        $rank = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown log level \"$level\".");
        if ($rank < $this->threshold) {
            return;
        }

        $record = [
            'time' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
            'level' => $level,
            'message' => self::interpolate((string) $message, $context),
            'service' => $this->service,
        ];
        $span = $this->tracer->active();
        if ($span !== null) {
            $record['trace_id'] = $span->traceId;
            $record['span_id'] = $span->spanId;
        }
        foreach ($context as $key => $value) {
            $record['context'][$key] = self::normalize($value);
        }

        $this->stream ??= fopen($this->target, 'a') ?: null;
        if ($this->stream !== null) {
            fwrite($this->stream, json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        }
    }

    public function dispose(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /** @param array<mixed> $context */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile() . ':' . $value->getLine(),
                'trace' => \array_slice(explode("\n", $value->getTraceAsString()), 0, 15),
                'previous' => $value->getPrevious() !== null && $depth < 3 ? self::normalize($value->getPrevious(), $depth + 1) : null,
            ];
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_RFC3339_EXTENDED);
        }
        if ($value instanceof \JsonSerializable) {
            return $value;
        }
        if (\is_object($value)) {
            return $value instanceof \Stringable ? (string) $value : '[object ' . $value::class . ']';
        }
        if (\is_array($value)) {
            return $depth > 5 ? '[array]' : array_map(static fn ($v) => self::normalize($v, $depth + 1), $value);
        }
        if (\is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }
}
