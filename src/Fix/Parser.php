<?php

declare(strict_types=1);

namespace Keel\Fix;

/**
 * Splits a TCP byte stream into complete FIX messages, using BodyLength to find the
 * trailer. Garbled data before a message start is skipped, as the spec requires.
 */
final class Parser
{
    private string $buffer = '';

    public function __construct(private readonly int $maxMessageBytes = 1_048_576)
    {
    }

    /** @return list<string> complete raw messages */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];
        while (true) {
            $start = strpos($this->buffer, '8=');
            if ($start === false) {
                $this->buffer = substr($this->buffer, -1); // keep a possible "8"
                break;
            }
            if ($start > 0) {
                $this->buffer = substr($this->buffer, $start);
            }
            if (preg_match('/^8=[^\x01]+\x019=(\d+)\x01/', $this->buffer, $m) !== 1) {
                if (\strlen($this->buffer) > 64 && preg_match('/^8=[^\x01]*\x01/', $this->buffer) !== 1) {
                    $this->buffer = substr($this->buffer, 2); // not a real start: skip it
                    continue;
                }
                break;
            }
            $length = (int) $m[1];
            if ($length > $this->maxMessageBytes) {
                throw new FixError("BodyLength $length exceeds the limit of {$this->maxMessageBytes} bytes.");
            }
            $total = \strlen($m[0]) + $length + 7; // trailer: "10=NNN\x01"
            if (\strlen($this->buffer) < $total) {
                break;
            }
            $messages[] = substr($this->buffer, 0, $total);
            $this->buffer = substr($this->buffer, $total);
        }

        return $messages;
    }
}
