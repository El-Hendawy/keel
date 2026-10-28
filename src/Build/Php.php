<?php

declare(strict_types=1);

namespace Keel\Build;

/** Helpers for emitting PHP source code. */
final class Php
{
    public static function string(string $value): string
    {
        return var_export($value, true);
    }

    /** Exports a value as a short-array PHP literal. */
    public static function export(mixed $value, int $indent = 0): string
    {
        if ($value instanceof \UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }
        if (!\is_array($value)) {
            return match (true) {
                $value === null => 'null',
                \is_bool($value) => $value ? 'true' : 'false',
                \is_float($value) => var_export($value, true),
                \is_int($value), \is_string($value) => var_export($value, true),
                default => throw new \InvalidArgumentException('Cannot export ' . get_debug_type($value) . ' to PHP code.'),
            };
        }
        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $indent + 1);
        $list = array_is_list($value);
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $pad . ($list ? '' : var_export($key, true) . ' => ') . self::export($item, $indent + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $indent) . ']';
    }

    /** Writes a file atomically so that a running worker never reads half a file. */
    public static function write(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir.");
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $path.");
        }
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
