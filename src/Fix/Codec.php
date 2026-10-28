<?php

declare(strict_types=1);

namespace Keel\Fix;

/**
 * The FIX tag=value wire format: SOH-separated fields framed by BeginString (8),
 * BodyLength (9) and CheckSum (10).
 */
final class Codec
{
    public const string SOH = "\x01";

    /** Header fields set by the session; other header tags come from $message->header. */
    public static function encode(Message $message, string $beginString): string
    {
        $body = '35=' . $message->type . self::SOH;
        $header = $message->header;
        foreach (Tag::HEADER as $tag) {
            if (isset($header[$tag])) {
                $body .= $tag . '=' . $header[$tag] . self::SOH;
                unset($header[$tag]);
            }
        }
        foreach ($header as $tag => $value) {
            $body .= $tag . '=' . $value . self::SOH;
        }
        foreach ($message->fields as [$tag, $value]) {
            if (str_contains($value, self::SOH)) {
                throw new \InvalidArgumentException("Tag $tag contains a SOH character.");
            }
            $body .= $tag . '=' . $value . self::SOH;
        }
        $head = '8=' . $beginString . self::SOH . '9=' . \strlen($body) . self::SOH;

        return $head . $body . '10=' . self::checksum($head . $body) . self::SOH;
    }

    public static function checksum(string $bytes): string
    {
        return str_pad((string) (array_sum(unpack('C*', $bytes) ?: []) % 256), 3, '0', \STR_PAD_LEFT);
    }

    /**
     * Parses one complete, framed message (as returned by Parser).
     *
     * @throws FixError when the framing, length or checksum is wrong
     */
    public static function decode(string $raw): Message
    {
        if (!str_ends_with($raw, self::SOH)) {
            throw new FixError('Message does not end with SOH.');
        }
        $parts = explode(self::SOH, substr($raw, 0, -1));
        $pairs = [];
        foreach ($parts as $part) {
            $eq = strpos($part, '=');
            if ($eq === false || $eq === 0 || !ctype_digit(substr($part, 0, $eq))) {
                throw new FixError("Malformed field \"$part\".");
            }
            $pairs[] = [(int) substr($part, 0, $eq), substr($part, $eq + 1)];
        }
        if (\count($pairs) < 4 || $pairs[0][0] !== Tag::BEGIN_STRING || $pairs[1][0] !== Tag::BODY_LENGTH || $pairs[2][0] !== Tag::MSG_TYPE || end($pairs)[0] !== Tag::CHECKSUM) {
            throw new FixError('Message must start with 8, 9, 35 and end with 10.');
        }
        $checksumAt = strrpos($raw, self::SOH . '10=');
        \assert($checksumAt !== false);
        if (self::checksum(substr($raw, 0, $checksumAt + 1)) !== end($pairs)[1]) {
            throw new FixError('CheckSum mismatch.');
        }
        $bodyStart = strpos($raw, self::SOH . '9=');
        \assert($bodyStart !== false);
        $bodyStart = (int) strpos($raw, self::SOH, $bodyStart + 1) + 1;
        if ($checksumAt + 1 - $bodyStart !== (int) $pairs[1][1]) {
            throw new FixError('BodyLength mismatch.');
        }

        $header = [Tag::BEGIN_STRING => $pairs[0][1]];
        $fields = [];
        foreach (\array_slice($pairs, 3, -1) as [$tag, $value]) {
            if (\in_array($tag, Tag::HEADER, true) && !isset($header[$tag]) && $fields === []) {
                $header[$tag] = $value;
            } else {
                $fields[] = [$tag, $value];
            }
        }

        return new Message($pairs[2][1], $fields, $header);
    }
}
