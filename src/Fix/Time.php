<?php

declare(strict_types=1);

namespace Keel\Fix;

/** UTCTimestamp, UTCDateOnly and UTCTimeOnly as FIX writes them. */
final class Time
{
    public static function timestamp(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-H:i:s.v');
    }

    public static function parse(string $value): \DateTimeImmutable
    {
        foreach (['Ymd-H:i:s.u', 'Ymd-H:i:s', 'Ymd'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new FixError("\"$value\" is not a UTCTimestamp.", 6);
    }
}
