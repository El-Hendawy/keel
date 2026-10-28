<?php

declare(strict_types=1);

namespace Keel\Fix\Store;

/**
 * Sequence numbers in `<dir>/seqnums` (written atomically) and sent messages appended to
 * `<dir>/messages.log`, one base64 line per message. Enough for a gateway on one node;
 * the directory must be on persistent storage.
 */
final class FileSessionStore implements SessionStore
{
    private int $sender;
    private int $target;

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create FIX store directory $directory.");
        }
        $raw = @file_get_contents($directory . '/seqnums');
        [$sender, $target] = \is_string($raw) ? array_map(intval(...), explode(' ', trim($raw)) + [1, 1]) : [1, 1];
        $this->sender = max(1, $sender);
        $this->target = max(1, $target);
    }

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
        $this->persist();
    }

    public function setNextTargetSeq(int $seq): void
    {
        $this->target = $seq;
        $this->persist();
    }

    public function saveSent(int $seq, string $raw): void
    {
        file_put_contents($this->directory . '/messages.log', $seq . ' ' . base64_encode($raw) . "\n", \FILE_APPEND | \LOCK_EX);
    }

    public function sent(int $from, int $to): array
    {
        $messages = [];
        $handle = @fopen($this->directory . '/messages.log', 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            [$seq, $data] = explode(' ', trim($line), 2) + [0 => '0', 1 => ''];
            $seq = (int) $seq;
            if ($seq >= $from && $seq <= $to) {
                $messages[$seq] = (string) base64_decode($data, true);
            }
        }
        fclose($handle);

        return $messages;
    }

    public function reset(): void
    {
        $this->sender = $this->target = 1;
        $this->persist();
        @unlink($this->directory . '/messages.log');
    }

    private function persist(): void
    {
        $tmp = $this->directory . '/seqnums.' . getmypid() . '.tmp';
        file_put_contents($tmp, $this->sender . ' ' . $this->target);
        rename($tmp, $this->directory . '/seqnums');
    }
}
