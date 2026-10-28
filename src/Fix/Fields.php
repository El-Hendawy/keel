<?php

declare(strict_types=1);

namespace Keel\Fix;

/** @internal runtime helpers for the generated FIX codecs */
final class Fields
{
    /**
     * Removes a repeating group from $pairs and returns its instances.
     *
     * @param list<array{0: int, 1: string}> $pairs
     * @param list<int> $members tags that may appear inside an instance
     * @return list<list<array{0: int, 1: string}>>
     */
    public static function extractGroup(array &$pairs, int $countTag, array $members): array
    {
        foreach ($pairs as $i => [$tag, $value]) {
            if ($tag !== $countTag) {
                continue;
            }
            if (!ctype_digit($value)) {
                throw new FixError("NoXXX ($countTag) must be a count.", 6, $countTag);
            }
            $count = (int) $value;
            $first = $members[0] ?? 0;
            $instances = [];
            $j = $i + 1;
            $total = \count($pairs);
            while ($j < $total && \in_array($pairs[$j][0], $members, true)) {
                if ($pairs[$j][0] === $first) {
                    $instances[] = [];
                } elseif ($instances === []) {
                    throw new FixError("Repeating group $countTag must start with tag $first.", 15, $pairs[$j][0]);
                }
                $instances[\count($instances) - 1][] = $pairs[$j];
                $j++;
            }
            if (\count($instances) !== $count) {
                throw new FixError("Repeating group $countTag declares $count instances, found " . \count($instances) . '.', 16, $countTag);
            }
            array_splice($pairs, $i, $j - $i);

            return $instances;
        }

        return [];
    }

    /**
     * @param list<array{0: int, 1: string}> $pairs
     * @return array<int, string> first value per tag
     */
    public static function index(array $pairs): array
    {
        $map = [];
        foreach ($pairs as [$tag, $value]) {
            $map[$tag] ??= $value;
        }

        return $map;
    }

    /** @param array<int, string> $f */
    public static function required(array $f, int $tag): string
    {
        return $f[$tag] ?? throw new FixError("Required tag $tag missing.", 1, $tag);
    }

    public static function int(string $value, int $tag): int
    {
        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : throw new FixError("Tag $tag must be an integer.", 6, $tag);
    }

    public static function float(string $value, int $tag): float
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1 ? (float) $value : throw new FixError("Tag $tag must be a decimal.", 6, $tag);
    }

    public static function bool(string $value, int $tag): bool
    {
        return match ($value) {
            'Y' => true,
            'N' => false,
            default => throw new FixError("Tag $tag must be Y or N.", 6, $tag),
        };
    }

    public static function time(string $value, int $tag): \DateTimeImmutable
    {
        try {
            return Time::parse($value);
        } catch (FixError) {
            throw new FixError("Tag $tag must be a UTCTimestamp.", 6, $tag);
        }
    }

    public static function decimal(float $value): string
    {
        return \Keel\Http\Toon\ToonEncoder::number($value);
    }

    public static function invalid(int $tag): FixError
    {
        return new FixError("Tag $tag has a value that is not allowed.", 5, $tag);
    }
}
