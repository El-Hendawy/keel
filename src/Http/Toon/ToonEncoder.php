<?php

declare(strict_types=1);

namespace Keel\Http\Toon;

/** @internal see Toon::encode() */
final readonly class ToonEncoder
{
    private string $mark;

    /** @param ','|"\t"|'|' $delimiter */
    public function __construct(private string $delimiter = ',', private int $indent = 2)
    {
        $this->mark = $delimiter === ',' ? '' : $delimiter;
    }

    public function encode(mixed $value): string
    {
        $value = self::normalize($value);
        $lines = [];
        if (\is_array($value) && array_is_list($value) && $value !== []) {
            $this->array('', $value, 0, $lines);
        } elseif (\is_array($value)) {
            $this->fields($value, 0, $lines);
        } else {
            $lines[] = $this->primitive($value);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $object
     * @param list<string> $lines
     */
    private function fields(array $object, int $depth, array &$lines): void
    {
        foreach ($object as $key => $value) {
            $name = self::key((string) $key);
            if (\is_array($value) && array_is_list($value)) {
                $this->array($name, $value, $depth, $lines);
            } elseif (\is_array($value)) {
                $lines[] = $this->pad($depth) . $name . ':';
                $this->fields($value, $depth + 1, $lines);
            } else {
                $lines[] = $this->pad($depth) . $name . ': ' . $this->primitive($value);
            }
        }
    }

    /**
     * @param list<mixed> $items
     * @param list<string> $lines
     */
    private function array(string $prefix, array $items, int $depth, array &$lines): void
    {
        $count = \count($items);
        $head = $this->pad($depth) . $prefix . '[' . $count . $this->mark . ']';
        if ($count === 0) {
            $lines[] = $head . ':';

            return;
        }
        if (array_filter($items, \is_array(...)) === []) {
            $lines[] = $head . ': ' . implode($this->delimiter, array_map($this->primitive(...), $items));

            return;
        }
        $columns = self::tableColumns($items);
        if ($columns !== null) {
            $lines[] = $head . '{' . implode($this->delimiter, array_map(self::key(...), $columns)) . '}:';
            foreach ($items as $row) {
                \assert(\is_array($row));
                $lines[] = $this->pad($depth + 1) . implode($this->delimiter, array_map($this->primitive(...), array_values($row)));
            }

            return;
        }
        $lines[] = $head . ':';
        foreach ($items as $item) {
            $this->listItem($item, $depth + 1, $lines);
        }
    }

    /** @param list<string> $lines */
    private function listItem(mixed $item, int $depth, array &$lines): void
    {
        if (!\is_array($item)) {
            $lines[] = $this->pad($depth) . '- ' . $this->primitive($item);

            return;
        }
        if ($item === []) {
            $lines[] = $this->pad($depth) . '- [0]:'; // PHP cannot tell an empty object from an empty list

            return;
        }
        // Render the item one level deeper, then put the hyphen in place of the first line's
        // indentation: "- " is exactly one indentation level wide, so everything stays aligned.
        $item_lines = [];
        array_is_list($item) ? $this->array('', $item, $depth + 1, $item_lines) : $this->fields($item, $depth + 1, $item_lines);
        $item_lines[0] = $this->pad($depth) . '- ' . ltrim($item_lines[0], ' ');
        array_push($lines, ...$item_lines);
    }

    /**
     * Arrays of objects with the same keys and only primitive values are written as tables.
     *
     * @param list<mixed> $items
     * @return list<string>|null
     */
    private static function tableColumns(array $items): ?array
    {
        $columns = null;
        foreach ($items as $item) {
            if (!\is_array($item) || $item === [] || array_is_list($item)) {
                return null;
            }
            $keys = array_map('strval', array_keys($item));
            if ($columns !== null && $keys !== $columns) {
                return null;
            }
            foreach ($item as $value) {
                if (\is_array($value)) {
                    return null;
                }
            }
            $columns = $keys;
        }

        return $columns;
    }

    private function primitive(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            \is_float($value) => self::number($value),
            \is_string($value) => $this->string($value),
            default => throw new ToonException('Cannot encode ' . get_debug_type($value) . ' as TOON.'),
        };
    }

    private function string(string $value): string
    {
        $quote = $value === ''
            || trim($value) !== $value
            || \in_array($value, ['true', 'false', 'null'], true)
            || preg_match('/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/', $value) === 1
            || preg_match('/[:"\\\\\[\]{}\x00-\x1f]/', $value) === 1
            || str_contains($value, $this->delimiter)
            || str_starts_with($value, '-');

        return $quote ? self::quote($value) : $value;
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) === 1 ? $key : self::quote($key);
    }

    private static function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    }

    /** Canonical decimal: no exponent, no trailing zeros, -0 is 0, NaN and infinities are null. */
    public static function number(float $value): string
    {
        if (!is_finite($value)) {
            return 'null';
        }
        if ($value == 0.0) {
            return '0';
        }
        $repr = json_encode($value, \JSON_THROW_ON_ERROR); // shortest round-trip digits
        if (preg_match('/^(-?)(\d)(?:\.(\d+))?e([+-]?\d+)$/i', $repr, $m) === 1) {
            $digits = $m[2] . $m[3];
            $point = 1 + (int) $m[4];
            $repr = $m[1] . match (true) {
                $point <= 0 => '0.' . str_repeat('0', -$point) . $digits,
                $point >= \strlen($digits) => $digits . str_repeat('0', $point - \strlen($digits)),
                default => substr($digits, 0, $point) . '.' . substr($digits, $point),
            };
        }

        return str_contains($repr, '.') ? rtrim(rtrim($repr, '0'), '.') : $repr;
    }

    private function pad(int $depth): string
    {
        return str_repeat(' ', $depth * $this->indent);
    }

    /** Objects become arrays: JsonSerializable, stdClass, date-times (ISO 8601) and backed enums. */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_RFC3339_EXTENDED);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_object($value)) {
            return array_map(self::normalize(...), get_object_vars($value));
        }
        if (\is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
