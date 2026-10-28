<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/** A 12-byte MongoDB ObjectId. */
final class ObjectId implements \JsonSerializable, \Stringable
{
    public readonly string $bytes;

    private static ?int $counter = null;

    private static ?string $process = null;

    public function __construct(?string $hex = null)
    {
        if ($hex === null) {
            self::$counter = ((self::$counter ?? random_int(0, 0xFFFFFF)) + 1) & 0xFFFFFF;
            self::$process ??= random_bytes(5);
            $this->bytes = pack('N', time()) . self::$process . substr(pack('N', self::$counter), 1);

            return;
        }
        if (preg_match('/^[0-9a-f]{24}$/i', $hex) !== 1) {
            throw new \InvalidArgumentException("\"$hex\" is not an ObjectId (24 hex characters).");
        }
        $this->bytes = (string) hex2bin($hex);
    }

    public function timestamp(): int
    {
        $time = unpack('N', $this->bytes);

        return \is_array($time) && \is_int($time[1]) ? $time[1] : 0;
    }

    public function __toString(): string
    {
        return bin2hex($this->bytes);
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
