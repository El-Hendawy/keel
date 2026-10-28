<?php

declare(strict_types=1);

namespace Keel\Realtime;

/** @internal RFC 6455 framing */
final class Frame
{
    public const int CONTINUATION = 0x0;
    public const int TEXT = 0x1;
    public const int BINARY = 0x2;
    public const int CLOSE = 0x8;
    public const int PING = 0x9;
    public const int PONG = 0xA;

    /** Server frames are never masked. */
    public static function encode(int $opcode, string $payload, bool $fin = true): string
    {
        $length = \strlen($payload);
        $head = \chr(($fin ? 0x80 : 0) | $opcode);
        if ($length < 126) {
            $head .= \chr($length);
        } elseif ($length < 65536) {
            $head .= \chr(126) . pack('n', $length);
        } else {
            $head .= \chr(127) . pack('J', $length);
        }

        return $head . $payload;
    }

    /** A client frame (masked), for tests and Keel's own clients. */
    public static function encodeMasked(int $opcode, string $payload, string $mask, bool $fin = true): string
    {
        $length = \strlen($payload);
        $head = \chr(($fin ? 0x80 : 0) | $opcode);
        $head .= match (true) {
            $length < 126 => \chr(0x80 | $length),
            $length < 65536 => \chr(0x80 | 126) . pack('n', $length),
            default => \chr(0x80 | 127) . pack('J', $length),
        };

        return $head . $mask . ($payload ^ str_repeat($mask, intdiv($length, 4) + 1));
    }

    /**
     * Decodes one frame from the start of $buffer.
     *
     * @return array{fin: bool, opcode: int, payload: string, length: int}|null null when incomplete
     * @throws WebSocketClosed with a close code on a protocol violation
     */
    public static function decode(string $buffer, bool $requireMask, int $maxPayload): ?array
    {
        if (\strlen($buffer) < 2) {
            return null;
        }
        $b0 = \ord($buffer[0]);
        $b1 = \ord($buffer[1]);
        if (($b0 & 0x70) !== 0) {
            throw new WebSocketClosed(1002, 'Reserved bits are set.');
        }
        $masked = ($b1 & 0x80) !== 0;
        if ($requireMask && !$masked) {
            throw new WebSocketClosed(1002, 'Client frames must be masked.');
        }
        $length = $b1 & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (\strlen($buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1] ?? 0;
            $offset = 4;
        } elseif ($length === 127) {
            if (\strlen($buffer) < 10) {
                return null;
            }
            $length = unpack('J', substr($buffer, 2, 8))[1] ?? 0;
            $offset = 10;
        }
        \assert(\is_int($length));
        if ($length > $maxPayload) {
            throw new WebSocketClosed(1009, 'Message too big.');
        }
        $opcode = $b0 & 0x0F;
        if ($opcode >= 0x8 && ($length > 125 || ($b0 & 0x80) === 0)) {
            throw new WebSocketClosed(1002, 'Control frames must be short and unfragmented.');
        }
        $mask = '';
        if ($masked) {
            if (\strlen($buffer) < $offset + 4) {
                return null;
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }
        if (\strlen($buffer) < $offset + $length) {
            return null;
        }
        $payload = substr($buffer, $offset, $length);
        if ($masked) {
            $payload ^= substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
        }

        return ['fin' => ($b0 & 0x80) !== 0, 'opcode' => $opcode, 'payload' => $payload, 'length' => $offset + $length];
    }
}
