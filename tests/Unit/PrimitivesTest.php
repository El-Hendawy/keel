<?php

declare(strict_types=1);

namespace Keel\Tests\Unit;

use Keel\Database\Row;
use Keel\Http\TrustedProxies;
use Keel\Observability\Metrics\Metrics;
use Keel\Observability\Tracing\Tracer;
use Keel\Testing\FrozenClock;
use Keel\Testing\SeededRandom;
use Keel\Support\UuidV7Generator;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class PrimitivesTest extends TestCase
{
    public function testRowsAreTyped(): void
    {
        $row = new Row(['id' => 7, 'count' => '12', 'name' => 'tea', 'note' => null, 'at' => '2026-01-01T10:00:00+00:00', 'paid' => 1]);

        self::assertSame(7, $row->int('id'));
        self::assertSame(12, $row->int('count'), 'Numeric strings from drivers become ints.');
        self::assertSame('7', $row->string('id'));
        self::assertNull($row->nullableString('note'));
        self::assertTrue($row->bool('paid'));
        self::assertSame('2026-01-01', $row->dateTime('at')->format('Y-m-d'));

        $this->expectException(\UnexpectedValueException::class);
        $row->int('name');
    }

    public function testMissingColumnsAreReported(): void
    {
        $this->expectExceptionMessage('Column "total" is not in the result set (have: id).');
        (new Row(['id' => 1]))->int('total');
    }

    public function testClientIpIgnoresForwardingHeadersFromUntrustedPeers(): void
    {
        $request = new ServerRequest('GET', '/', ['X-Forwarded-For' => '6.6.6.6, 10.0.0.9'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.2']);

        self::assertSame('10.0.0.2', (new TrustedProxies())->clientIp($request));
        self::assertSame('6.6.6.6', (new TrustedProxies(['10.0.0.0/8']))->clientIp($request));
        self::assertTrue(TrustedProxies::inRange('2001:db8::1', '2001:db8::/32'));
        self::assertFalse(TrustedProxies::inRange('192.168.1.1', '10.0.0.0/8'));
    }

    public function testMetricsRenderPrometheusText(): void
    {
        $metrics = new Metrics();
        $metrics->increment('jobs_total', ['queue' => 'mail'], help: 'Jobs.');
        $metrics->increment('jobs_total', ['queue' => 'mail']);
        $metrics->observe('latency_seconds', 0.2, ['route' => '/x'], [0.1, 0.5]);
        $text = $metrics->render();

        self::assertStringContainsString('# TYPE jobs_total counter', $text);
        self::assertMatchesRegularExpression('/jobs_total\{queue="mail",worker="\d+"\} 2/', $text);
        self::assertMatchesRegularExpression('/latency_seconds_bucket\{route="\/x",worker="\d+",le="0.1"\} 0/', $text);
        self::assertMatchesRegularExpression('/latency_seconds_bucket\{route="\/x",worker="\d+",le="0.5"\} 1/', $text);
        self::assertMatchesRegularExpression('/latency_seconds_count\{route="\/x",worker="\d+"\} 1/', $text);
    }

    public function testUuidV7IsTimeOrderedAndWellFormed(): void
    {
        $clock = new FrozenClock('2026-10-28T12:00:00+00:00');
        $ids = new UuidV7Generator($clock, new SeededRandom(1));
        $first = $ids->generate();
        $clock->advance('+1 second');
        $second = $ids->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
        self::assertLessThan($second, $first);
    }

    public function testTraceparentParsing(): void
    {
        self::assertSame(
            ['0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', true],
            Tracer::parseTraceparent('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'),
        );
        self::assertNull(Tracer::parseTraceparent('00-00000000000000000000000000000000-b7ad6b7169203331-01'));
        self::assertNull(Tracer::parseTraceparent('garbage'));
    }
}
