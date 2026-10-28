<?php

declare(strict_types=1);

namespace Keel\NoSql\DynamoDb;

/**
 * PHP values to DynamoDB attribute values and back: strings S, numbers N (kept exact: ints
 * come back as int, decimals as strings), bool BOOL, null NULL, lists L, maps M.
 * Floats are written with their shortest exact representation; prefer decimal strings for money.
 */
final class Marshaler
{
    /**
     * @param array<string, mixed> $values
     * @return array<string, array<string, mixed>>
     */
    public static function item(array $values): array
    {
        $item = [];
        foreach ($values as $name => $value) {
            $item[$name] = self::value($value);
        }

        return $item;
    }

    /**
     * @param array<mixed> $item
     * @return array<string, mixed>
     */
    public static function unmarshal(array $item): array
    {
        $values = [];
        foreach ($item as $name => $value) {
            $values[(string) $name] = \is_array($value) ? self::from($value) : null;
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public static function value(mixed $value): array
    {
        return match (true) {
            $value === null => ['NULL' => true],
            \is_bool($value) => ['BOOL' => $value],
            \is_int($value) => ['N' => (string) $value],
            \is_float($value) => ['N' => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
            \is_string($value) => ['S' => $value],
            $value instanceof \BackedEnum => self::value($value->value),
            $value instanceof \JsonSerializable => self::value($value->jsonSerialize()),
            \is_array($value) && array_is_list($value) && $value !== [] => ['L' => array_map(self::value(...), $value)],
            \is_array($value) => ['M' => $value === [] ? new \stdClass() : self::item(array_combine(array_map(strval(...), array_keys($value)), $value))],
            default => throw new \InvalidArgumentException('Cannot store ' . get_debug_type($value) . ' in DynamoDB.'),
        };
    }

    /** @param array<mixed> $attribute */
    private static function from(array $attribute): mixed
    {
        $type = array_key_first($attribute);
        $value = $attribute[$type];

        return match ($type) {
            'S' => \is_string($value) ? $value : '',
            'N' => \is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : $value,
            'BOOL' => (bool) $value,
            'NULL' => null,
            'L' => array_map(static fn (mixed $v): mixed => \is_array($v) ? self::from($v) : null, \is_array($value) ? array_values($value) : []),
            'M' => \is_array($value) ? self::unmarshal($value) : [],
            'B' => \is_string($value) ? base64_decode($value) : '',
            'SS', 'NS', 'BS' => \is_array($value) ? array_values($value) : [],
            default => null,
        };
    }
}
