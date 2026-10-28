<?php

declare(strict_types=1);

namespace Keel\Money;

use Keel\Validation\CurrencyCode;
use Keel\Validation\Decimal;
use Keel\Validation\ValidationFailed;
use Keel\Validation\Violation;

/**
 * An exact amount of one currency, held in integer minor units: never a float.
 *
 *     $price = Money::of('19.99', 'EUR');
 *     $total = $price->times(3)->plus(Money::of('4.50', 'EUR'));   // 64.47 EUR
 *     [$a, $b, $c] = $total->allocate(1, 1, 1);                     // 21.49, 21.49, 21.49 — no cent lost
 *     $vat = $total->times('0.21', Rounding::HalfUp);
 *
 * It is also an API type: as an input it is `{"amount": "19.99", "currency": "EUR"}`, validated
 * (an ISO 4217 code, a decimal string with no more places than the currency has), and it is
 * written back the same way. Amounts are strings in JSON so no client parses them as floats.
 */
final readonly class Money implements \JsonSerializable
{
    /** The amount as a decimal string with the currency's places, e.g. "19.90" or "-5" (JPY). */
    public string $amount;

    /** The ISO 4217 code. */
    public string $currency;

    private int $minor;

    public function __construct(#[Decimal] string $amount, #[CurrencyCode] string $currency)
    {
        if (!Currency::exists($currency)) {
            throw new ValidationFailed([new Violation('currency', 'currency', 'Must be an ISO 4217 currency code.')]);
        }
        $scale = Currency::minorUnits($currency);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            throw new ValidationFailed([new Violation('amount', 'decimal', 'Must be a decimal number as a string, like "12.50".')]);
        }
        $whole = ltrim($m[2], '0');
        $fraction = $m[3] ?? '';
        if (rtrim(substr($fraction, $scale), '0') !== '') {
            throw new ValidationFailed([new Violation('amount', 'scale', "$currency amounts have at most $scale decimal place(s).")]);
        }
        $digits = ltrim($whole . str_pad(substr($fraction, 0, $scale), $scale, '0'), '0');
        if (\strlen($digits) > 19 || (\strlen($digits) === 19 && strcmp($digits, (string) \PHP_INT_MAX) > 0)) {
            throw new ValidationFailed([new Violation('amount', 'range', 'The amount is too large.')]);
        }
        $minor = (int) ($digits === '' ? '0' : $digits);
        $this->minor = $m[1] === '-' ? -$minor : $minor;
        $this->currency = $currency;
        $this->amount = self::format($this->minor, $scale);
    }

    public static function of(string|int $amount, string $currency): self
    {
        return new self((string) $amount, $currency);
    }

    /** From minor units: Money::ofMinor(1999, 'EUR') is 19.99 EUR. */
    public static function ofMinor(int $minor, string $currency): self
    {
        return new self(self::format($minor, Currency::minorUnits($currency)), $currency);
    }

    public static function zero(string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    /** The amount in minor units (cents for EUR). */
    public function minor(): int
    {
        return $this->minor;
    }

    public function plus(self ...$others): self
    {
        $sum = $this->minor;
        foreach ($others as $other) {
            $this->same($other);
            $sum = self::checked($sum + $other->minor);
        }

        return self::ofMinor($sum, $this->currency);
    }

    public function minus(self ...$others): self
    {
        $result = $this->minor;
        foreach ($others as $other) {
            $this->same($other);
            $result = self::checked($result - $other->minor);
        }

        return self::ofMinor($result, $this->currency);
    }

    public function negate(): self
    {
        return self::ofMinor(self::checked(-$this->minor), $this->currency);
    }

    public function abs(): self
    {
        return $this->minor < 0 ? $this->negate() : $this;
    }

    /**
     * Multiplies by an integer or an exact decimal string ("0.21", "1.075"); never a float.
     */
    public function times(int|string $factor, Rounding $rounding = Rounding::HalfEven): self
    {
        if (\is_int($factor)) {
            return self::ofMinor(self::multiply($this->minor, $factor), $this->currency);
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,9}))?$/', $factor, $m) !== 1) {
            throw new \InvalidArgumentException("Multiply money by an integer or a decimal string with up to 9 places, not \"$factor\".");
        }
        $places = \strlen($m[3] ?? '');
        $numerator = (int) ($m[2] . ($m[3] ?? ''));
        $product = self::multiply($this->minor, $m[1] === '-' ? -$numerator : $numerator);

        return self::ofMinor(self::divide($product, 10 ** $places, $rounding), $this->currency);
    }

    /** Divides by an integer, rounding the result (use allocate() to split without losing cents). */
    public function dividedBy(int $divisor, Rounding $rounding = Rounding::HalfEven): self
    {
        if ($divisor === 0) {
            throw new \DivisionByZeroError('Cannot divide money by zero.');
        }

        return self::ofMinor(self::divide($divisor < 0 ? -$this->minor : $this->minor, abs($divisor), $rounding), $this->currency);
    }

    /**
     * Splits by ratios without creating or losing a minor unit: allocate(1, 1, 1) of 100.00 is
     * 33.34, 33.33, 33.33. Leftover units go to the shares with the largest remainders.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        $total = array_sum($ratios);
        if ($ratios === [] || $total <= 0 || min($ratios) < 0) {
            throw new \InvalidArgumentException('Allocate by one or more non-negative ratios with a positive sum.');
        }
        $amount = abs($this->minor);
        $shares = [];
        $remainders = [];
        foreach ($ratios as $i => $ratio) {
            $product = self::multiply($amount, $ratio);
            $shares[$i] = intdiv($product, $total);
            $remainders[$i] = $product % $total;
        }
        $left = $amount - array_sum($shares);
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($left === 0) {
                break;
            }
            $shares[$i]++;
            $left--;
        }
        $sign = $this->minor < 0 ? -1 : 1;

        return array_values(array_map(fn (int $share): self => self::ofMinor($sign * $share, $this->currency), $shares));
    }

    /** @return list<self> $parts equal shares that add up to this amount */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new \InvalidArgumentException('Split into at least one part.');
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    public function compareTo(self $other): int
    {
        $this->same($other);

        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /** @return array{amount: string, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }

    private function same(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new CurrencyMismatch("Cannot combine {$this->currency} and {$other->currency}: convert one first.");
        }
    }

    private static function format(int $minor, int $scale): string
    {
        $digits = str_pad((string) abs($minor), $scale + 1, '0', \STR_PAD_LEFT);
        $text = $scale === 0 ? $digits : substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);

        return ($minor < 0 ? '-' : '') . $text;
    }

    private static function checked(int|float $value): int
    {
        if (!\is_int($value)) {
            throw new \OverflowException('The amount is out of range.');
        }

        return $value;
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a !== 0 && $b !== 0 && (abs($a) > intdiv(\PHP_INT_MAX, abs($b)))) {
            throw new \OverflowException('The amount is out of range.');
        }

        return $a * $b;
    }

    /** Integer division of $value by a positive $divisor, rounded as asked. */
    private static function divide(int $value, int $divisor, Rounding $rounding): int
    {
        $quotient = intdiv($value, $divisor);
        $remainder = $value % $divisor;
        if ($remainder === 0) {
            return $quotient;
        }
        $sign = $value < 0 ? -1 : 1;
        $twice = 2 * abs($remainder);
        $away = match ($rounding) {
            Rounding::Up => true,
            Rounding::Down => false,
            Rounding::Ceiling => $sign > 0,
            Rounding::Floor => $sign < 0,
            Rounding::HalfUp => $twice >= $divisor,
            Rounding::HalfDown => $twice > $divisor,
            Rounding::HalfEven => $twice > $divisor || ($twice === $divisor && $quotient % 2 !== 0),
        };

        return $away ? $quotient + $sign : $quotient;
    }
}
