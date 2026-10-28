<?php

declare(strict_types=1);

namespace Keel\NoSql\InfluxDb;

use Keel\NoSql\JsonHttp;
use Keel\NoSql\NoSqlException;
use Psr\Http\Client\ClientInterface;

/**
 * InfluxDB (time series) through the v2 HTTP API, which InfluxDB 2 and 3 both accept for writes.
 *
 *     $influx->write('payments', ['currency' => 'EUR', 'psp' => 'adyen'], ['amount' => 19.99, 'count' => 1], $at);
 *     $rows = $influx->query('from(bucket: "metrics") |> range(start: -1h) |> filter(fn: (r) => r._measurement == "payments")');
 */
final readonly class InfluxDbClient
{
    private JsonHttp $json;

    public function __construct(ClientInterface $http, string $url, string $token, private string $org, private string $bucket)
    {
        $this->json = new JsonHttp($http, rtrim($url, '/'), ['Authorization' => 'Token ' . $token], 'InfluxDB');
    }

    /**
     * Writes one point. Field values: int (stored as integer), float, bool or string.
     *
     * @param array<string, string> $tags indexed dimensions
     * @param array<string, int|float|bool|string> $fields the values
     */
    public function write(string $measurement, array $tags, array $fields, ?\DateTimeInterface $at = null): void
    {
        $this->writeLines([self::line($measurement, $tags, $fields, $at)]);
    }

    /** @param list<string> $lines line protocol, e.g. from line() */
    public function writeLines(array $lines): void
    {
        $this->json->request('POST', '/api/v2/write?' . http_build_query(['org' => $this->org, 'bucket' => $this->bucket, 'precision' => 'ns']), implode("\n", $lines), ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Runs a Flux query and returns its rows (all tables concatenated), keyed by column.
     *
     * @return list<array<string, string>>
     */
    public function query(string $flux): array
    {
        [, $csv] = $this->json->request('POST', '/api/v2/query?' . http_build_query(['org' => $this->org]), $flux, ['Content-Type' => 'application/vnd.flux', 'Accept' => 'application/csv']);
        if ($csv !== null && !\is_string($csv)) {
            throw new NoSqlException('InfluxDB returned JSON where CSV was expected.');
        }

        return self::parseCsv((string) $csv);
    }

    /**
     * One point in line protocol, with the escaping rules for measurements, tags and fields.
     *
     * @param array<string, string> $tags
     * @param array<string, int|float|bool|string> $fields
     */
    public static function line(string $measurement, array $tags, array $fields, ?\DateTimeInterface $at = null): string
    {
        if ($fields === []) {
            throw new \InvalidArgumentException('An InfluxDB point needs at least one field.');
        }
        $key = static fn (string $s): string => str_replace([',', '=', ' '], ['\,', '\=', '\ '], $s);
        ksort($tags);
        $line = str_replace([',', ' '], ['\,', '\ '], $measurement);
        foreach ($tags as $name => $value) {
            $line .= ',' . $key($name) . '=' . $key($value);
        }
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $key($name) . '=' . match (true) {
                \is_int($value) => $value . 'i',
                \is_float($value) => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                \is_bool($value) => $value ? 'true' : 'false',
                default => '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"',
            };
        }
        $line .= ' ' . implode(',', $parts);
        if ($at !== null) {
            $line .= ' ' . $at->format('U') . str_pad($at->format('u'), 6, '0') . '000';
        }

        return $line;
    }

    /** @return list<array<string, string>> */
    private static function parseCsv(string $csv): array
    {
        $rows = [];
        $header = null;
        foreach (preg_split('/\r?\n/', $csv) ?: [] as $line) {
            if ($line === '') {
                $header = null; // a blank line separates tables
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if ($header === null) {
                $header = $cells;
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name !== '' && $name !== null && $name !== 'result' && $name !== 'table') {
                    $row[(string) $name] = (string) ($cells[$i] ?? '');
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
