<?php

declare(strict_types=1);

namespace Keel\Scheduling;

/**
 * Standard five-field cron (minute hour day-of-month month day-of-week) with lists,
 * ranges, steps and the @hourly/@daily/@weekly/@monthly/@yearly macros. When both day
 * fields are restricted, a time matches either one, as in Vixie cron.
 */
final readonly class CronExpression
{
    private const array MACROS = [
        '@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *',
    ];
    private const array RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /** @param array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>, 4: list<int>} $fields */
    private function __construct(public string $expression, private array $fields, private bool $dayOfMonthAny, private bool $dayOfWeekAny)
    {
    }

    public static function parse(string $expression): self
    {
        $normalized = self::MACROS[strtolower(trim($expression))] ?? trim($expression);
        $parts = preg_split('/\s+/', $normalized) ?: [];
        if (\count($parts) !== 5) {
            throw new \InvalidArgumentException("Cron expression \"$expression\" must have five fields: minute hour day-of-month month day-of-week.");
        }
        $fields = [];
        foreach ($parts as $i => $part) {
            $fields[$i] = self::field($part, self::RANGES[$i][0], self::RANGES[$i][1], $expression);
        }
        $fields[4] = array_values(array_unique(array_map(static fn (int $d): int => $d % 7, $fields[4]))); // 7 is Sunday too

        return new self($expression, [$fields[0], $fields[1], $fields[2], $fields[3], $fields[4]], $parts[2] === '*', $parts[4] === '*');
    }

    public function isDue(\DateTimeInterface $at): bool
    {
        $utc = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));
        [$minutes, $hours, $days, $months, $weekdays] = $this->fields;
        if (!\in_array((int) $utc->format('i'), $minutes, true) || !\in_array((int) $utc->format('G'), $hours, true) || !\in_array((int) $utc->format('n'), $months, true)) {
            return false;
        }
        $dayMatches = \in_array((int) $utc->format('j'), $days, true);
        $weekdayMatches = \in_array((int) $utc->format('w'), $weekdays, true);

        return match (true) {
            $this->dayOfMonthAny && $this->dayOfWeekAny => true,
            $this->dayOfMonthAny => $weekdayMatches,
            $this->dayOfWeekAny => $dayMatches,
            default => $dayMatches || $weekdayMatches,
        };
    }

    /** @return list<int> */
    private static function field(string $part, int $min, int $max, string $expression): array
    {
        $values = [];
        foreach (explode(',', $part) as $item) {
            if (preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $item, $m) !== 1) {
                throw new \InvalidArgumentException("Invalid cron field \"$item\" in \"$expression\".");
            }
            [$from, $to] = $m[1] === '*' ? [$min, $max] : array_map(intval(...), array_pad(explode('-', $m[1]), 2, null));
            $to = $m[1] !== '*' && !str_contains($m[1], '-') ? (isset($m[2]) ? $max : $from) : $to;
            $step = isset($m[2]) ? (int) $m[2] : 1;
            if ($from < $min || $to > $max || $from > $to || $step < 1) {
                throw new \InvalidArgumentException("Cron field \"$item\" in \"$expression\" is outside $min-$max.");
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $values[] = $v;
            }
        }

        return $values;
    }
}
