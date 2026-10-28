<?php

declare(strict_types=1);

namespace Keel\Fix\Store;

/** For tests and sessions that reset sequence numbers on every logon. */
final class InMemorySessionStore implements SessionStore
{
    private int $sender = 1;
    private int $target = 1;

    /** @var array<int, string> */
    private array $sent = [];

    public function nextSenderSeq(): int
    {
        return $this->sender;
    }

    public function nextTargetSeq(): int
    {
        return $this->target;
    }

    public function setNextSenderSeq(int $seq): void
    {
        $this->sender = $seq;
    }

    public function setNextTargetSeq(int $seq): void
    {
        $this->target = $seq;
    }

    public function saveSent(int $seq, string $raw): void
    {
        $this->sent[$seq] = $raw;
    }

    public function sent(int $from, int $to): array
    {
        return array_filter($this->sent, static fn (int $seq): bool => $seq >= $from && $seq <= $to, \ARRAY_FILTER_USE_KEY);
    }

    public function reset(): void
    {
        $this->sender = $this->target = 1;
        $this->sent = [];
    }
}
