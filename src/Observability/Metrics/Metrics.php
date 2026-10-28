<?php

declare(strict_types=1);

namespace Keel\Observability\Metrics;

use Keel\Container\Attribute\Singleton;

/**
 * Counters and histograms kept in worker memory and exposed in Prometheus text format.
 *
 * Each worker reports its own series, labelled with its process id; aggregate with
 * sum() in your queries. RED metrics (rate, errors, duration) for every route are
 * recorded by the framework without any code.
 */
#[Singleton]
final class Metrics
{
    public const array DEFAULT_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /** @var array<string, array{help: string, type: string}> */
    private array $meta = [];

    /** @var array<string, array<string, array{labels: array<string, string>, value: float}>> name => series key => series */
    private array $counters = [];

    /** @var array<string, array<string, array{labels: array<string, string>, bounds: list<float>, counts: list<int>, sum: float, count: int}>> */
    private array $histograms = [];

    /** @param array<string, string> $labels */
    public function increment(string $name, array $labels = [], float $by = 1.0, string $help = ''): void
    {
        $this->meta[$name] ??= ['help' => $help, 'type' => 'counter'];
        $key = self::key($labels);
        $this->counters[$name][$key] ??= ['labels' => $labels, 'value' => 0.0];
        $this->counters[$name][$key]['value'] += $by;
    }

    /**
     * @param array<string, string> $labels
     * @param list<float> $buckets upper bounds, ascending
     */
    public function observe(string $name, float $value, array $labels = [], array $buckets = self::DEFAULT_BUCKETS, string $help = ''): void
    {
        $this->meta[$name] ??= ['help' => $help, 'type' => 'histogram'];
        $key = self::key($labels);
        $series = $this->histograms[$name][$key] ?? [
            'labels' => $labels,
            'bounds' => $buckets,
            'counts' => array_map(static fn (): int => 0, $buckets),
            'sum' => 0.0,
            'count' => 0,
        ];
        $counts = [];
        foreach ($series['bounds'] as $i => $bound) {
            $counts[] = ($series['counts'][$i] ?? 0) + ($value <= $bound ? 1 : 0); // cumulative, as Prometheus expects
        }
        $series['counts'] = $counts;
        $series['sum'] += $value;
        $series['count']++;
        $this->histograms[$name][$key] = $series;
    }

    public function render(): string
    {
        $worker = ['worker' => (string) getmypid()];
        $out = [];
        foreach ($this->meta as $name => $meta) {
            if ($meta['help'] !== '') {
                $out[] = "# HELP $name {$meta['help']}";
            }
            $out[] = "# TYPE $name {$meta['type']}";
            foreach ($this->counters[$name] ?? [] as $series) {
                $out[] = $name . self::labels($series['labels'] + $worker) . ' ' . self::number($series['value']);
            }
            foreach ($this->histograms[$name] ?? [] as $series) {
                $labels = $series['labels'] + $worker;
                foreach ($series['bounds'] as $i => $bound) {
                    $out[] = $name . '_bucket' . self::labels($labels + ['le' => self::number($bound)]) . ' ' . $series['counts'][$i];
                }
                $out[] = $name . '_bucket' . self::labels($labels + ['le' => '+Inf']) . ' ' . $series['count'];
                $out[] = $name . '_sum' . self::labels($labels) . ' ' . self::number($series['sum']);
                $out[] = $name . '_count' . self::labels($labels) . ' ' . $series['count'];
            }
        }

        return implode("\n", $out) . "\n";
    }

    /** @param array<string, string> $labels */
    private static function key(array $labels): string
    {
        ksort($labels);

        return http_build_query($labels);
    }

    /** @param array<string, string> $labels */
    private static function labels(array $labels): string
    {
        $parts = [];
        foreach ($labels as $label => $value) {
            $parts[] = $label . '="' . addcslashes($value, "\\\"\n") . '"';
        }

        return '{' . implode(',', $parts) . '}';
    }

    private static function number(float $value): string
    {
        return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
    }
}
