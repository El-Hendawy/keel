<?php

declare(strict_types=1);

namespace Keel\Orm;

/** @internal conversions between PHP values and what every PDO driver returns or accepts */
final class Values
{
    public const string DATETIME = 'Y-m-d H:i:s.u';

    public static function string(mixed $v): string
    {
        return \is_scalar($v) ? (string) $v : throw new \UnexpectedValueException('Expected a string column, got ' . get_debug_type($v) . '.');
    }

    public static function int(mixed $v): int
    {
        return match (true) {
            \is_int($v) => $v,
            \is_string($v) && preg_match('/^-?\d+$/', $v) === 1, \is_float($v) => (int) $v,
            default => throw new \UnexpectedValueException('Expected an integer column, got ' . get_debug_type($v) . '.'),
        };
    }

    public static function float(mixed $v): float
    {
        return \is_int($v) || \is_float($v) || (\is_string($v) && is_numeric($v)) ? (float) $v : throw new \UnexpectedValueException('Expected a number column.');
    }

    /** true/false, 1/0, '1'/'0', 't'/'f' (PostgreSQL text mode), 'Y'/'N' */
    public static function bool(mixed $v): bool
    {
        return match (true) {
            \is_bool($v) => $v,
            \in_array($v, [1, '1', 't', 'true', 'Y'], true) => true,
            \in_array($v, [0, '0', 'f', 'false', 'N'], true) => false,
            default => throw new \UnexpectedValueException('Expected a boolean column.'),
        };
    }

    /** Date-times are stored in UTC without an offset, which every database accepts. */
    public static function dateTime(mixed $v): \DateTimeImmutable
    {
        if ($v instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($v);
        }
        $s = self::string($v);
        try {
            return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \UnexpectedValueException("\"$s\" is not a date-time.", 0, $e);
        }
    }

    public static function toDateTime(\DateTimeInterface $v): string
    {
        return \DateTimeImmutable::createFromInterface($v)->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME);
    }

    /** @return array<mixed> */
    public static function json(mixed $v): array
    {
        $decoded = json_decode(self::string($v), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : throw new \UnexpectedValueException('Expected a JSON array or object.');
    }

    /** @param array<mixed> $v */
    public static function toJson(array $v): string
    {
        return json_encode($v, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Decimals stay strings end to end, so money never meets floating point. */
    public static function decimal(mixed $v): string
    {
        $s = self::string($v);
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return str_starts_with($s, '.') ? '0' . $s : ($s === '' || $s === '-' ? '0' : $s);
    }
}
