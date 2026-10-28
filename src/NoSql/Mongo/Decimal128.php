<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

use Keel\Money\Money;

/**
 * BSON decimal128 (IEEE 754-2008, binary integer decimal): MongoDB's exact decimal type, the
 * right one for money. Converts to and from decimal strings without floating point.
 *
 *     ['price' => Decimal128::fromString('19.99')]   // or Decimal128::fromMoney($money)
 *     $doc['price']->toString();                     // "19.99"
 */
final readonly class Decimal128 implements \JsonSerializable, \Stringable
{
    private const int BIAS = 6176;

    /** @param string $bytes 16 bytes, little-endian, as stored in BSON */
    public function __construct(public string $bytes)
    {
        if (\strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('A decimal128 is 16 bytes.');
        }
    }

    public static function fromString(string $decimal): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $m) !== 1) {
            throw new \InvalidArgumentException("\"$decimal\" is not a decimal number.");
        }
        $fraction = $m[3] ?? '';
        $digits = ltrim($m[2] . $fraction, '0');
        if (\strlen($digits) > 34) {
            throw new \InvalidArgumentException('A decimal128 holds at most 34 significant digits.');
        }
        $limbs = [0, 0, 0, 0]; // little-endian 32-bit words
        foreach (str_split(str_pad($digits, (int) ceil(max(1, \strlen($digits)) / 9) * 9, '0', \STR_PAD_LEFT), 9) as $chunk) {
            $carry = (int) $chunk;
            for ($i = 0; $i < 4; $i++) {
                $product = $limbs[$i] * 1_000_000_000 + $carry;
                $limbs[$i] = $product & 0xFFFFFFFF;
                $carry = $product >> 32;
            }
        }
        $exponent = -\strlen($fraction) + self::BIAS;
        $high = ($m[1] === '-' ? 0x80000000 : 0) | ($exponent << 17) | ($limbs[3] & 0x1FFFF);

        return new self(pack('VVVV', $limbs[0], $limbs[1], $limbs[2], $high));
    }

    public static function fromMoney(Money $money): self
    {
        return self::fromString($money->amount);
    }

    public function toString(): string
    {
        $words = array_values(array_filter(unpack('V4', $this->bytes) ?: [], \is_int(...)));
        if (\count($words) !== 4) {
            throw new \LogicException('A decimal128 is 16 bytes.');
        }
        [$w0, $w1, $w2, $w3] = $words;
        $negative = ($w3 >> 31) === 1;
        if ((($w3 >> 29) & 3) === 3) {
            $special = ($w3 >> 26) & 0x1F;
            if ($special === 0x1E) {
                return ($negative ? '-' : '') . 'Infinity';
            }
            if ($special === 0x1F) {
                return 'NaN';
            }
            $exponent = (($w3 >> 15) & 0x3FFF) - self::BIAS;
            $limbs = [0, 0, 0, 0]; // a non-canonical coefficient counts as zero
        } else {
            $exponent = (($w3 >> 17) & 0x3FFF) - self::BIAS;
            $limbs = [$w3 & 0x1FFFF, $w2, $w1, $w0]; // big-endian for division
        }
        $digits = '';
        while ($limbs !== [0, 0, 0, 0]) {
            $remainder = 0;
            foreach ($limbs as $i => $limb) {
                $value = ($remainder << 32) | $limb;
                $limbs[$i] = intdiv($value, 1_000_000_000);
                $remainder = $value % 1_000_000_000;
            }
            $digits = str_pad((string) $remainder, 9, '0', \STR_PAD_LEFT) . $digits;
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        if ($exponent > 0) {
            $text = \strlen($digits) + $exponent > 40 ? $digits . 'E+' . $exponent : $digits . str_repeat('0', $exponent);
        } elseif ($exponent < 0) {
            $places = -$exponent;
            $padded = str_pad($digits, $places + 1, '0', \STR_PAD_LEFT);
            $text = substr($padded, 0, -$places) . '.' . substr($padded, -$places);
        } else {
            $text = $digits;
        }

        return ($negative ? '-' : '') . $text;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
