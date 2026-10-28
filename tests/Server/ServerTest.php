<?php

declare(strict_types=1);

namespace Keel\Tests\Server;

use Keel\Realtime\Frame;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** `keel run` end to end: a real server with two processes, driven over TCP. */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('curl')]
final class ServerTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(41000, 49000);
        $env = getenv() + ['KEEL_ENV' => 'dev'];
        self::$process = proc_open(
            [\PHP_BINARY, __DIR__ . '/../../bin/keel', 'run', '--app=' . __DIR__ . '/../Fixtures/Realtime/app.php', '--port=' . self::$port, '--workers=2'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env,
        ) ?: null;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('The server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process, \SIGTERM);
            for ($i = 0; $i < 100 && proc_get_status(self::$process)['running']; $i++) {
                usleep(50_000);
            }
            proc_close(self::$process);
        }
    }

    public function testManyWaitingRequestsShareTwoProcesses(): void
    {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 30; $i++) {
            $handle = curl_init('http://127.0.0.1:' . self::$port . '/slow/400');
            curl_setopt($handle, \CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        $start = microtime(true);
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.1);
        } while ($running > 0);
        $elapsed = microtime(true) - $start;
        $pids = [];
        foreach ($handles as $handle) {
            self::assertSame(200, curl_getinfo($handle, \CURLINFO_RESPONSE_CODE));
            $body = json_decode((string) curl_multi_getcontent($handle), true);
            self::assertIsArray($body);
            $pids[$body['pid']] = true;
        }
        self::assertLessThan(2.0, $elapsed, '30 requests waiting 400 ms each would take 6 s on two blocking workers.');
        self::assertCount(2, $pids, 'Both processes served requests.');
    }

    public function testKeepAliveChunkedBodiesAndErrors(): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $error, 2);
        self::assertNotFalse($socket);
        fwrite($socket, "POST /count HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\nContent-Type: application/json\r\n\r\n2\r\n{}\r\n0\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 200 OK', self::readResponse($socket));
        fwrite($socket, "GET /nope HTTP/1.1\r\nHost: x\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 404', self::readResponse($socket), 'Same connection, second request.');
        fwrite($socket, "GARBAGE\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 400', self::readResponse($socket));
        fclose($socket);
    }

    public function testServerSentEvents(): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $error, 2);
        self::assertNotFalse($socket);
        stream_set_timeout($socket, 3);
        fwrite($socket, "GET /events HTTP/1.1\r\nHost: x\r\nAccept: text/event-stream\r\n\r\n");
        $received = '';
        while (substr_count($received, 'event: tick') < 3 && !feof($socket)) {
            $received .= (string) fread($socket, 8192);
        }
        self::assertStringContainsString("Content-Type: text/event-stream; charset=utf-8\r\n", $received);
        self::assertStringContainsString("Transfer-Encoding: chunked\r\n", $received);
        self::assertStringContainsString("event: tick\nid: 3\ndata: {\"n\":3}\n\n", $received);
        fclose($socket);
    }

    public function testWebSocketsAndBroadcast(): void
    {
        $alice = self::connect('lobby');
        $bob = self::connect('lobby');
        self::assertSame('welcome to lobby', self::receive($alice));
        self::assertSame('welcome to lobby', self::receive($bob));

        self::sendFrame($alice, Frame::TEXT, 'hello');
        self::assertSame('echo:hello', self::receive($alice));
        self::sendFrame($alice, Frame::TEXT, str_repeat('é', 40_000));
        self::assertSame('echo:' . str_repeat('é', 40_000), self::receive($alice));

        // Broadcast through the hub. Without Redis the hub is per process, and Bob may be
        // connected to the other process; testPublishedMessagesReachSubscribersInEveryProcess covers that.
        self::sendFrame($alice, Frame::TEXT, 'say:hi room');
        self::assertSame('hi room', self::receive($alice));
        if (getenv('KEEL_TEST_REDIS_URL') !== false) {
            self::assertSame('hi room', self::receive($bob));
        }

        // Invalid UTF-8 in a text frame closes the connection with 1007.
        self::sendFrame($bob, Frame::TEXT, "\xC3\x28");
        $close = self::receiveFrame($bob);
        self::assertSame(Frame::CLOSE, $close['opcode']);
        self::assertSame(1007, unpack('n', substr($close['payload'], 0, 2))[1] ?? 0);

        self::sendFrame($alice, Frame::CLOSE, pack('n', 1000));
        self::assertSame(Frame::CLOSE, self::receiveFrame($alice)['opcode']);
    }

    public function testPublishedMessagesReachSubscribersInEveryProcess(): void
    {
        if (getenv('KEEL_TEST_REDIS_URL') === false) {
            self::markTestSkipped('Set KEEL_TEST_REDIS_URL to test the Redis-backed hub across processes.');
        }
        $clients = [];
        for ($i = 0; $i < 6; $i++) {
            $clients[] = $client = self::connect('global');
            self::receive($client);
        }
        usleep(200_000);
        $publish = curl_init('http://127.0.0.1:' . self::$port . '/publish?channel=room.global&message=everyone');
        curl_setopt_array($publish, [\CURLOPT_POST => true, \CURLOPT_RETURNTRANSFER => true]);
        curl_exec($publish);
        foreach ($clients as $client) {
            self::assertSame('everyone', self::receive($client));
        }
    }

    /** @return resource */
    private static function connect(string $room)
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $error, 2);
        self::assertNotFalse($socket);
        stream_set_timeout($socket, 5);
        $key = base64_encode(random_bytes(16));
        fwrite($socket, "GET /ws/$room HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $head .= (string) fread($socket, 1);
        }
        self::assertStringStartsWith('HTTP/1.1 101', $head);
        self::assertStringContainsString('Sec-WebSocket-Accept: ' . base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), $head);

        return $socket;
    }

    /** @param resource $socket */
    private static function sendFrame($socket, int $opcode, string $payload): void
    {
        fwrite($socket, Frame::encodeMasked($opcode, $payload, random_bytes(4)));
    }

    /** @param resource $socket */
    private static function receive($socket): string
    {
        return self::receiveFrame($socket)['payload'];
    }

    /**
     * @param resource $socket
     * @return array{fin: bool, opcode: int, payload: string, length: int}
     */
    private static function receiveFrame($socket): array
    {
        static $buffers = [];
        $id = (int) $socket;
        $buffers[$id] ??= '';
        while (($frame = Frame::decode($buffers[$id], false, 10_000_000)) === null) {
            $chunk = fread($socket, 65536);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                self::fail('The connection closed.');
            }
            $buffers[$id] .= $chunk;
        }
        $buffers[$id] = substr($buffers[$id], $frame['length']);

        return $frame;
    }

    /** @param resource $socket */
    private static function readResponse($socket): string
    {
        stream_set_timeout($socket, 3);
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $response .= (string) fread($socket, 1);
        }
        if (preg_match('/Content-Length: (\d+)/i', $response, $m) === 1 && (int) $m[1] > 0) {
            $response .= (string) fread($socket, (int) $m[1]);
        }

        return $response;
    }
}
