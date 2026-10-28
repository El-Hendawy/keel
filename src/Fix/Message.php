<?php

declare(strict_types=1);

namespace Keel\Fix;

/**
 * A FIX message as ordered tag=value fields. Order matters (repeating groups), so fields
 * are a list of pairs, not a map; get() returns the first occurrence.
 */
final class Message
{
    /**
     * @param list<array{0: int, 1: string}> $fields body fields, without header and trailer
     * @param array<int, string> $header
     */
    public function __construct(public readonly string $type, public array $fields = [], public array $header = [])
    {
    }

    /**
     * @param array<int, string> $fields
     * @param array<int, string> $header
     */
    public static function of(string $type, array $fields = [], array $header = []): self
    {
        $pairs = [];
        foreach ($fields as $tag => $value) {
            $pairs[] = [$tag, $value];
        }

        return new self($type, $pairs, $header);
    }

    public function get(int $tag): ?string
    {
        if (isset($this->header[$tag])) {
            return $this->header[$tag];
        }
        foreach ($this->fields as [$t, $value]) {
            if ($t === $tag) {
                return $value;
            }
        }

        return null;
    }

    public function with(int $tag, string $value): self
    {
        $copy = clone $this;
        $copy->fields[] = [$tag, $value];

        return $copy;
    }

    public function seqNum(): int
    {
        return (int) ($this->header[Tag::MSG_SEQ_NUM] ?? 0);
    }

    public function isPossDup(): bool
    {
        return ($this->header[Tag::POSS_DUP_FLAG] ?? 'N') === 'Y';
    }
}
