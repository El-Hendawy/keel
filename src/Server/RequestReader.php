<?php

declare(strict_types=1);

namespace Keel\Server;

use Keel\Async\Io;
use Keel\Async\TimeoutException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/** @internal reads HTTP/1.x requests from a non-blocking socket, one at a time */
final class RequestReader
{
    private string $buffer = '';

    /** @param resource $socket */
    public function __construct(private $socket, private readonly ServerOptions $options, private readonly string $remoteAddress, private readonly int $remotePort)
    {
    }

    /**
     * @return ServerRequestInterface|null null when the client closed the connection or stayed idle
     * @throws HttpError for malformed or oversized requests
     */
    public function next(bool $first): ?ServerRequestInterface
    {
        $deadline = microtime(true) + ($first ? $this->options->headerTimeout : $this->options->keepAliveTimeout);
        while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
            if (\strlen($this->buffer) > $this->options->maxHeaderBytes) {
                throw new HttpError(431, 'Request headers too large.');
            }
            if (!$this->fill($deadline)) {
                if (trim($this->buffer) !== '') {
                    throw new HttpError(408, 'The request was not completed in time.');
                }

                return null;
            }
        }
        if ($end > $this->options->maxHeaderBytes) {
            throw new HttpError(431, 'Request headers too large.');
        }
        $head = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 4);
        $lines = explode("\r\n", $head);
        if (preg_match('#^([A-Z]{1,16}) (\S+) HTTP/(1\.[01])$#', (string) array_shift($lines), $m) !== 1) {
            throw new HttpError(400, 'Malformed request line.');
        }
        [, $method, $target, $version] = $m;
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === ' ' || $line[0] === "\t" || ($colon = strpos($line, ':')) === false || $colon === 0) {
                throw new HttpError(400, 'Malformed header.');
            }
            $name = substr($line, 0, $colon);
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                throw new HttpError(400, 'Malformed header name.');
            }
            $headers[strtolower($name)][] = trim(substr($line, $colon + 1));
        }
        $host = $headers['host'][0] ?? null;
        if ($host === null && $version === '1.1') {
            throw new HttpError(400, 'Missing Host header.');
        }
        $body = $this->body($headers);

        $path = str_starts_with($target, '/') ? $target : (string) (parse_url($target, \PHP_URL_PATH) ?? '/') . (($q = parse_url($target, \PHP_URL_QUERY)) ? "?$q" : '');
        $server = [
            'REMOTE_ADDR' => $this->remoteAddress,
            'REMOTE_PORT' => $this->remotePort,
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'SERVER_PROTOCOL' => "HTTP/$version",
            'REQUEST_TIME_FLOAT' => microtime(true),
            'REQUEST_TIME' => time(),
        ];
        $request = new ServerRequest($method, 'http://' . ($host ?? 'localhost') . $path, $headers, $body, $version, $server);
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        $cookies = [];
        foreach (explode(';', implode(';', $headers['cookie'] ?? [])) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($k !== '') {
                $cookies[$k] = urldecode($v);
            }
        }

        return $request->withQueryParams($query)->withCookieParams($cookies);
    }

    /** Bytes received after the request head of a WebSocket handshake belong to the WebSocket. */
    public function takeBuffer(): string
    {
        $buffer = $this->buffer;
        $this->buffer = '';

        return $buffer;
    }

    /** @param array<string, list<string>> $headers */
    private function body(array $headers): string
    {
        $chunked = str_contains(strtolower(implode(',', $headers['transfer-encoding'] ?? [])), 'chunked');
        if ($chunked && isset($headers['content-length'])) {
            throw new HttpError(400, 'Content-Length and Transfer-Encoding cannot both be set.');
        }
        $deadline = microtime(true) + $this->options->bodyTimeout;
        if (!$chunked) {
            $length = $headers['content-length'][0] ?? '0';
            if (!ctype_digit($length) || \count(array_unique($headers['content-length'] ?? ['0'])) > 1) {
                throw new HttpError(400, 'Invalid Content-Length.');
            }
            if ((int) $length > $this->options->maxBodyBytes) {
                throw new HttpError(413, "Request bodies are limited to {$this->options->maxBodyBytes} bytes.");
            }
            $this->continue($headers, (int) $length);
            while (\strlen($this->buffer) < (int) $length) {
                if (!$this->fill($deadline)) {
                    throw new HttpError(408, 'The request body was not completed in time.');
                }
            }
            $body = substr($this->buffer, 0, (int) $length);
            $this->buffer = substr($this->buffer, (int) $length);

            return $body;
        }
        $this->continue($headers, 1);
        $body = '';
        while (true) {
            while (($eol = strpos($this->buffer, "\r\n")) === false) {
                if (!$this->fill($deadline)) {
                    throw new HttpError(408, 'The request body was not completed in time.');
                }
            }
            $size = hexdec(trim(explode(';', substr($this->buffer, 0, $eol))[0]));
            if (!\is_int($size) || \strlen($body) + $size > $this->options->maxBodyBytes) {
                throw new HttpError(413, "Request bodies are limited to {$this->options->maxBodyBytes} bytes.");
            }
            while (\strlen($this->buffer) < $eol + 2 + $size + 2) {
                if (!$this->fill($deadline)) {
                    throw new HttpError(408, 'The request body was not completed in time.');
                }
            }
            $body .= substr($this->buffer, $eol + 2, $size);
            $this->buffer = substr($this->buffer, $eol + 2 + $size + 2);
            if ($size === 0) {
                return $body; // trailers are not supported; the final CRLF was consumed above
            }
        }
    }

    /** @param array<string, list<string>> $headers */
    private function continue(array $headers, int $length): void
    {
        if ($length > 0 && strtolower($headers['expect'][0] ?? '') === '100-continue' && $this->buffer === '') {
            Io::writeAll($this->socket, "HTTP/1.1 100 Continue\r\n\r\n", 5.0);
        }
    }

    /** @return bool false on close or timeout */
    private function fill(float $deadline): bool
    {
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            return false;
        }
        if ($chunk !== '') {
            $this->buffer .= $chunk;

            return true;
        }
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return false;
        }
        try {
            Io::readable($this->socket, $left);
        } catch (TimeoutException) {
            return false;
        }

        return true;
    }
}
