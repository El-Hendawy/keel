<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/**
 * BSON (bsonspec.org) to and from PHP values.
 *
 * | BSON | PHP |
 * | --- | --- |
 * | document | array with string keys, an empty array, or \stdClass |
 * | array | a non-empty list, or `new BsonArray([...])` (also for an empty array) |
 * | string, double, bool, null | string, float, bool, null |
 * | int32 / int64 | int (int32 when it fits; `new Int64($n)` forces a long) |
 * | ObjectId, binary, decimal128 | ObjectId, Binary, Decimal128 |
 * | UTC datetime | \DateTimeImmutable (millisecond precision, UTC) |
 * | timestamp, regex | Timestamp, Regex |
 */
final class Bson
{
    /** @param array<mixed>|object $document */
    public static function encode(array|object $document): string
    {
        $body = '';
        foreach (self::members($document) as $key => $value) {
            $body .= self::element((string) $key, $value);
        }

        return pack('V', \strlen($body) + 5) . $body . "\0";
    }

    /** @return array<string, mixed> */
    public static function decode(string $bytes): array
    {
        $offset = 0;
        $document = self::document($bytes, $offset, false);
        if ($offset !== \strlen($bytes)) {
            throw new MongoException('Trailing bytes after a BSON document.');
        }
        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param array<mixed>|object $document
     * @return iterable<array-key, mixed>
     */
    private static function members(array|object $document): iterable
    {
        if ($document instanceof BsonArray) {
            return $document->items;
        }

        return \is_array($document) ? $document : get_object_vars($document);
    }

    private static function element(string $key, mixed $value): string
    {
        if (str_contains($key, "\0")) {
            throw new MongoException('BSON keys cannot contain NUL bytes.');
        }
        $name = $key . "\0";

        return match (true) {
            $value === null => "\x0A" . $name,
            \is_bool($value) => "\x08" . $name . ($value ? "\x01" : "\x00"),
            \is_int($value) => $value >= -2147483648 && $value <= 2147483647 ? "\x10" . $name . pack('V', $value & 0xFFFFFFFF) : "\x12" . $name . pack('P', $value),
            \is_float($value) => "\x01" . $name . pack('e', $value),
            \is_string($value) => "\x02" . $name . pack('V', \strlen($value) + 1) . $value . "\0",
            $value instanceof BsonArray => "\x04" . $name . self::encode($value),
            \is_array($value) => ($value !== [] && array_is_list($value) ? "\x04" : "\x03") . $name . self::encode($value),
            $value instanceof ObjectId => "\x07" . $name . $value->bytes,
            $value instanceof Int64 => "\x12" . $name . pack('P', $value->value),
            $value instanceof Binary => "\x05" . $name . pack('V', \strlen($value->data)) . \chr($value->subtype) . $value->data,
            $value instanceof Decimal128 => "\x13" . $name . $value->bytes,
            $value instanceof \DateTimeInterface => "\x09" . $name . pack('P', (int) $value->format('U') * 1000 + (int) $value->format('v')),
            $value instanceof Timestamp => "\x11" . $name . pack('VV', $value->increment, $value->seconds),
            $value instanceof Regex => "\x0B" . $name . $value->pattern . "\0" . $value->flags . "\0",
            $value instanceof \BackedEnum => self::element($key, $value->value),
            $value instanceof \JsonSerializable => self::element($key, $value->jsonSerialize()),
            $value instanceof \stdClass => "\x03" . $name . self::encode($value),
            default => throw new MongoException('Cannot store ' . get_debug_type($value) . " in BSON (key \"$key\")."),
        };
    }

    /** @return array<array-key, mixed> */
    private static function document(string $bytes, int &$offset, bool $list): array
    {
        $length = self::int32($bytes, $offset);
        $end = $offset - 4 + $length;
        if ($length < 5 || $end > \strlen($bytes) || $bytes[$end - 1] !== "\0") {
            throw new MongoException('Malformed BSON document.');
        }
        $result = [];
        while ($offset < $end - 1) {
            $type = \ord($bytes[$offset++]);
            $nul = strpos($bytes, "\0", $offset);
            if ($nul === false || $nul >= $end) {
                throw new MongoException('Malformed BSON key.');
            }
            $key = substr($bytes, $offset, $nul - $offset);
            $offset = $nul + 1;
            $value = self::value($type, $bytes, $offset);
            if ($list) {
                $result[] = $value;
            } else {
                $result[$key] = $value;
            }
        }
        $offset = $end;

        return $result;
    }

    private static function value(int $type, string $bytes, int &$offset): mixed
    {
        switch ($type) {
            case 0x01:
                $value = unpack('e', $bytes, $offset);
                $offset += 8;

                return \is_array($value) && \is_float($value[1]) ? $value[1] : throw new MongoException('Truncated BSON.');
            case 0x02:
            case 0x0D:
            case 0x0E:
                $length = self::int32($bytes, $offset);
                $value = substr($bytes, $offset, $length - 1);
                $offset += $length;

                return $value;
            case 0x03:
                return self::document($bytes, $offset, false);
            case 0x04:
                return self::document($bytes, $offset, true);
            case 0x05:
                $length = self::int32($bytes, $offset);
                $subtype = \ord($bytes[$offset]);
                $data = substr($bytes, $offset + 1, $length);
                $offset += 1 + $length;

                return new Binary($data, $subtype);
            case 0x06:
            case 0x0A:
            case 0xFF:
            case 0x7F:
                return null;
            case 0x07:
                $id = new ObjectId(bin2hex(substr($bytes, $offset, 12)));
                $offset += 12;

                return $id;
            case 0x08:
                return $bytes[$offset++] !== "\x00";
            case 0x09:
                $ms = self::int64($bytes, $offset);
                $date = \DateTimeImmutable::createFromFormat('U.v', \sprintf('%d.%03d', intdiv($ms, 1000) - ($ms % 1000 < 0 ? 1 : 0), ($ms % 1000 + 1000) % 1000), new \DateTimeZone('UTC'));
                if ($date === false) {
                    throw new MongoException('Invalid BSON datetime.');
                }

                return $date;
            case 0x0B:
                $patternEnd = (int) strpos($bytes, "\0", $offset);
                $pattern = substr($bytes, $offset, $patternEnd - $offset);
                $flagsEnd = (int) strpos($bytes, "\0", $patternEnd + 1);
                $flags = substr($bytes, $patternEnd + 1, $flagsEnd - $patternEnd - 1);
                $offset = $flagsEnd + 1;

                return new Regex($pattern, $flags);
            case 0x10:
                $value = self::int32($bytes, $offset);

                return $value >= 0x80000000 ? $value - 0x100000000 : $value;
            case 0x11:
                $increment = self::int32($bytes, $offset);

                return new Timestamp(self::int32($bytes, $offset), $increment);
            case 0x12:
                return self::int64($bytes, $offset);
            case 0x13:
                $value = new Decimal128(substr($bytes, $offset, 16));
                $offset += 16;

                return $value;
            default:
                throw new MongoException(\sprintf('Unsupported BSON type 0x%02X.', $type));
        }
    }

    private static function int32(string $bytes, int &$offset): int
    {
        $value = unpack('V', $bytes, $offset);
        if (!\is_array($value) || !\is_int($value[1])) {
            throw new MongoException('Truncated BSON.');
        }
        $offset += 4;

        return $value[1];
    }

    private static function int64(string $bytes, int &$offset): int
    {
        $value = unpack('P', $bytes, $offset);
        if (!\is_array($value) || !\is_int($value[1])) {
            throw new MongoException('Truncated BSON.');
        }
        $offset += 8;

        return $value[1];
    }
}
