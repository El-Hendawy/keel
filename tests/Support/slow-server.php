<?php

// A tiny single-process HTTP server for tests: every response is delayed by 300 ms, and
// the delays overlap because connections are multiplexed with stream_select. Unlike
// `php -S`, it does not depend on worker processes being available on the host.

declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on $port: $error\n");
    exit(1);
}
stream_set_blocking($server, false);

/** @var array<int, array{socket: resource, buffer: string, due: ?float}> $clients */
$clients = [];
while (true) {
    $read = [$server];
    foreach ($clients as $client) {
        if ($client['due'] === null) {
            $read[] = $client['socket'];
        }
    }
    $write = $except = [];
    if (stream_select($read, $write, $except, 0, 10_000) === false) {
        break;
    }
    foreach ($read as $socket) {
        if ($socket === $server) {
            $connection = @stream_socket_accept($server, 0);
            if ($connection !== false) {
                stream_set_blocking($connection, false);
                $clients[(int) $connection] = ['socket' => $connection, 'buffer' => '', 'due' => null];
            }
            continue;
        }
        $id = (int) $socket;
        $chunk = fread($socket, 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($socket)) {
                fclose($socket);
                unset($clients[$id]);
            }
            continue;
        }
        $clients[$id]['buffer'] .= $chunk;
        if (str_contains($clients[$id]['buffer'], "\r\n\r\n")) {
            $clients[$id]['due'] = microtime(true) + 0.3;
        }
    }
    $now = microtime(true);
    foreach ($clients as $id => $client) {
        if ($client['due'] !== null && $client['due'] <= $now) {
            $path = explode(' ', $client['buffer'])[1] ?? '/';
            $body = json_encode(['path' => $path]);
            stream_set_blocking($client['socket'], true);
            fwrite($client['socket'], "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen((string) $body) . "\r\nConnection: close\r\n\r\n" . $body);
            fclose($client['socket']);
            unset($clients[$id]);
        }
    }
}
