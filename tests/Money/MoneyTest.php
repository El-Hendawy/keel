<?php

declare(strict_types=1);

namespace Keel\Tests\Money;

use Keel\Money\Currency;
use Keel\Money\CurrencyMismatch;
use Keel\Money\Money;
use Keel\Money\Rounding;
use Keel\Validation\Bic;
use Keel\Validation\CardNumber;
use Keel\Validation\Iban;
use Keel\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testParsesAndFormatsWithTheCurrencyScale(): void
    {
        self::assertSame(['19.90', 1990], [Money::of('19.9', 'EUR')->amount, Money::of('19.9', 'EUR')->minor()]);
        self::assertSame('1000', Money::of('1000', 'JPY')->amount);
        self::assertSame('1.500', Money::of('1.5', 'KWD')->amount);
        self::assertSame('-0.05', Money::ofMinor(-5, 'USD')->amount);
        self::assertSame('0.00', Money::of('-0.00', 'EUR')->amount);
        self::assertSame('12.30', Money::of('12.3000', 'EUR')->amount, 'trailing zeros beyond the scale are fine');
        self::assertSame(['amount' => '7.00', 'currency' => 'EUR'], Money::of(7, 'EUR')->jsonSerialize());
        self::assertSame('7.00 EUR', (string) Money::of(7, 'EUR'));
        self::assertSame([2, 0, 3, 4], [Currency::minorUnits('EUR'), Currency::minorUnits('JPY'), Currency::minorUnits('BHD'), Currency::minorUnits('CLF')]);
        self::assertFalse(Currency::exists('XAU'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalid(): iterable
    {
        yield 'too many places' => ['1.234', 'EUR', 'scale'];
        yield 'yen has no cents' => ['1.5', 'JPY', 'scale'];
        yield 'not a number' => ['1e3', 'EUR', 'decimal'];
        yield 'unknown currency' => ['1', 'ABC', 'currency'];
        yield 'too large' => ['92233720368547758.08', 'EUR', 'range'];
    }

    #[DataProvider('invalid')]
    public function testRefusesInvalidAmounts(string $amount, string $currency, string $code): void
    {
        try {
            Money::of($amount, $currency);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertSame($code, $e->violations[0]->code);
        }
    }

    public function testArithmeticIsExact(): void
    {
        $price = Money::of('19.99', 'EUR');
        self::assertSame('64.47', $price->times(3)->plus(Money::of('4.50', 'EUR'))->amount);
        self::assertSame('0.30', Money::of('0.10', 'EUR')->plus(Money::of('0.20', 'EUR'))->amount, 'no 0.30000000000000004');
        self::assertSame('-5.00', Money::of('5', 'EUR')->minus(Money::of('10', 'EUR'))->amount);
        self::assertTrue(Money::of('5', 'EUR')->greaterThan(Money::of('4.99', 'EUR')));
        self::assertTrue(Money::of('5', 'EUR')->equals(Money::of('5.00', 'EUR')));

        $this->expectException(CurrencyMismatch::class);
        $price->plus(Money::of('1', 'USD'));
    }

    public function testRoundingModes(): void
    {
        $amount = Money::of('0.25', 'EUR');   // 25 cents * 0.5 = 12.5 cents
        $cases = [
            [Rounding::HalfEven, '0.12'], [Rounding::HalfUp, '0.13'], [Rounding::HalfDown, '0.12'],
            [Rounding::Up, '0.13'], [Rounding::Down, '0.12'], [Rounding::Ceiling, '0.13'], [Rounding::Floor, '0.12'],
        ];
        foreach ($cases as [$mode, $expected]) {
            self::assertSame($expected, $amount->times('0.5', $mode)->amount, $mode->name);
            self::assertSame('-' . $expected, $amount->negate()->times('0.5', $mode === Rounding::Ceiling ? Rounding::Floor : ($mode === Rounding::Floor ? Rounding::Ceiling : $mode))->amount, $mode->name . ' (negative)');
        }
        self::assertSame('0.14', Money::of('0.27', 'EUR')->times('0.5')->amount, 'half-even: 13.5 rounds to 14');
        self::assertSame('21.00', Money::of('100', 'EUR')->times('0.21')->amount);
        self::assertSame('3.33', Money::of('10', 'EUR')->dividedBy(3)->amount);
    }

    public function testAllocationNeverLosesAMinorUnit(): void
    {
        self::assertSame(['33.34', '33.33', '33.33'], array_map(static fn (Money $m): string => $m->amount, Money::of('100', 'EUR')->split(3)));
        self::assertSame(['0.03', '0.02'], array_map(static fn (Money $m): string => $m->amount, Money::of('0.05', 'EUR')->allocate(1, 1)));
        self::assertSame(['-0.07', '-0.03'], array_map(static fn (Money $m): string => $m->amount, Money::of('-0.10', 'EUR')->allocate(70, 30)));
        $parts = Money::of('1000.01', 'EUR')->allocate(1, 2, 3, 4);
        self::assertSame('1000.01', Money::zero('EUR')->plus(...$parts)->amount);
    }

    public function testOverflowIsAnError(): void
    {
        $this->expectException(\OverflowException::class);
        Money::ofMinor(\PHP_INT_MAX, 'EUR')->plus(Money::ofMinor(1, 'EUR'));
    }

    public function testFinancialIdentifiers(): void
    {
        foreach (['DE89370400440532013000', 'GB82WEST12345698765432', 'NL91ABNA0417164300', 'BE71096123456769', 'FR1420041010050500013M02606'] as $iban) {
            self::assertTrue(Iban::isValid($iban), $iban);
        }
        foreach (['DE89370400440532013001', 'DE8937040044053201300', 'de89370400440532013000', 'DE89 3704 0044 0532 0130 00', 'XX'] as $iban) {
            self::assertFalse(Iban::isValid($iban), $iban);
        }
        foreach (['4111111111111111', '5555555555554444', '378282246310005', '6011111111111117'] as $pan) {
            self::assertTrue(CardNumber::isValid($pan), $pan);
        }
        self::assertFalse(CardNumber::isValid('4111111111111112'));
        self::assertFalse(CardNumber::isValid('4111-1111-1111-1111'));
        self::assertSame('411111******1111', CardNumber::mask('4111 1111 1111 1111'));
        self::assertSame(1, preg_match('/' . Bic::PATTERN . '/', 'DEUTDEFF500'));
        self::assertSame(0, preg_match('/' . Bic::PATTERN . '/', 'DEUTDEF'));
    }
}
