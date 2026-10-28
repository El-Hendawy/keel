<?php

declare(strict_types=1);

namespace Keel\Tests\Async;

use Keel\Http\Client\CurlHttpClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Keel\Async\await_all;

#[RequiresPhpExtension('curl')]
final class HttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(20000, 40000);
        $command = [\PHP_BINARY, __DIR__ . '/../Support/slow-server.php', (string) self::$port];
        self::$server = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes) ?: null;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::markTestSkipped('Could not start the PHP built-in server.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server, 9);
            proc_close(self::$server);
        }
    }

    public function testRequestsInsideAwaitAllOverlap(): void
    {
        $client = new CurlHttpClient();
        $url = 'http://127.0.0.1:' . self::$port;
        $start = microtime(true);

        [$a, $b, $c] = await_all(
            static fn () => $client->sendRequest(new Request('GET', "$url/a")),
            static fn () => $client->sendRequest(new Request('GET', "$url/b")),
            static fn () => $client->sendRequest(new Request('GET', "$url/c")),
        );
        $elapsed = microtime(true) - $start;

        self::assertSame('{"path":"\/a"}', (string) $a->getBody());
        self::assertSame('{"path":"\/c"}', (string) $c->getBody());
        self::assertSame(200, $b->getStatusCode());
        self::assertLessThan(0.75, $elapsed, 'Three 300 ms responses should overlap.');
    }

    public function testOutsideAwaitAllTheClientSimplyBlocks(): void
    {
        $response = (new CurlHttpClient())->sendRequest(new Request('GET', 'http://127.0.0.1:' . self::$port . '/sync'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }
}
