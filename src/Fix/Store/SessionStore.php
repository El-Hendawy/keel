<?php

declare(strict_types=1);

namespace Keel\Fix\Store;

/** Sequence numbers and sent messages of one session, so it survives restarts and can resend. */
interface SessionStore
{
    public function nextSenderSeq(): int;

    public function nextTargetSeq(): int;

    public function setNextSenderSeq(int $seq): void;

    public function setNextTargetSeq(int $seq): void;

    /** Records a sent message for resending. */
    public function saveSent(int $seq, string $raw): void;

    /** @return array<int, string> sent messages with $from <= seq <= $to, by sequence number */
    public function sent(int $from, int $to): array;

    /** Starts both sequences at 1 and forgets sent messages (ResetSeqNumFlag). */
    public function reset(): void;
}
