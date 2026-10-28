<?php

declare(strict_types=1);

namespace Keel\Observability\Tracing;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;

/**
 * Sends spans to an OpenTelemetry collector over OTLP/HTTP (JSON encoding).
 * Export runs after the response has been sent, and failures never affect requests.
 */
#[Singleton]
final readonly class OtlpHttpExporter implements SpanExporter
{
    public function __construct(
        #[Config('tracing.otlp_endpoint')] private string $endpoint = 'http://127.0.0.1:4318/v1/traces',
        #[Config('app.name')] private string $serviceName = 'keel-app',
        #[Config('tracing.export_timeout_ms')] private int $timeoutMs = 500,
    ) {
    }

    public function export(array $spans): void
    {
        if ($spans === [] || !\function_exists('curl_init')) {
            return;
        }

        $payload = [
            'resourceSpans' => [[
                'resource' => ['attributes' => [self::attribute('service.name', $this->serviceName)]],
                'scopeSpans' => [[
                    'scope' => ['name' => 'keel'],
                    'spans' => array_map(self::span(...), $spans),
                ]],
            ]],
        ];

        $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($body === false) {
            return;
        }
        $handle = curl_init($this->endpoint);
        curl_setopt_array($handle, [
            \CURLOPT_POST => true,
            \CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            \CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
        ]);
        @curl_exec($handle);
        curl_close($handle);
    }

    /** @return array<string, mixed> */
    private static function span(Span $span): array
    {
        $attributes = [];
        foreach ($span->attributes as $key => $value) {
            if ($value !== null) {
                $attributes[] = self::attribute($key, $value);
            }
        }

        return array_filter([
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'parentSpanId' => $span->parentSpanId,
            'name' => $span->name,
            'kind' => match ($span->kind) {
                Span::KIND_SERVER => 2,
                Span::KIND_CLIENT => 3,
                Span::KIND_PRODUCER => 4,
                Span::KIND_CONSUMER => 5,
                default => 1,
            },
            'startTimeUnixNano' => (string) $span->startNs,
            'endTimeUnixNano' => (string) ($span->endNs ?? $span->startNs),
            'attributes' => $attributes,
            'events' => array_map(static fn (array $e): array => [
                'name' => $e['name'],
                'timeUnixNano' => (string) $e['timeNs'],
                'attributes' => array_map(self::attribute(...), array_keys($e['attributes']), array_values($e['attributes'])),
            ], $span->events),
            'status' => $span->error ? ['code' => 2, 'message' => $span->statusMessage] : ['code' => 1],
        ], static fn ($v) => $v !== null);
    }

    /** @return array{key: string, value: array<string, scalar>} */
    private static function attribute(string $key, string|int|float|bool $value): array
    {
        return ['key' => $key, 'value' => match (true) {
            \is_bool($value) => ['boolValue' => $value],
            \is_int($value) => ['intValue' => (string) $value],
            \is_float($value) => ['doubleValue' => $value],
            default => ['stringValue' => $value],
        }];
    }
}
