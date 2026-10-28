<?php

declare(strict_types=1);

namespace Keel\Validation;

/** Runtime helpers called by generated hydrators. */
final class Coerce
{
    /** Parses an RFC 3339 timestamp, with or without fractional seconds. */
    public static function dateTime(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }
        $format = str_contains($value, '.') ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP';
        $date = \DateTimeImmutable::createFromFormat($format, str_replace('Z', '+00:00', $value));

        return $date === false ? null : $date;
    }

    public static function int(string $value): ?int
    {
        return preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : null;
    }

    public static function float(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function bool(string $value): ?bool
    {
        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
