<?php

declare(strict_types=1);

namespace Keel\Money;

/**
 * ISO 4217 currencies and their minor units (decimal places): EUR 2, JPY 0, KWD 3, CLF 4.
 * Codes without a minor unit (gold, SDRs, testing codes) are not money here.
 */
final class Currency
{
    private const array ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const array THREE = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];
    private const array FOUR = ['CLF', 'UYW'];
    private const array TWO = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BMD', 'BND',
        'BOB', 'BOV', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHE', 'CHF', 'CHW', 'CNY', 'COP', 'COU',
        'CRC', 'CUP', 'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS',
        'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR', 'JMD', 'KES', 'KGS', 'KHR',
        'KPW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU',
        'MUR', 'MVR', 'MWK', 'MXN', 'MXV', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK',
        'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SLL',
        'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH',
        'USD', 'USN', 'UYU', 'UZS', 'VED', 'VES', 'WST', 'XCD', 'XCG', 'YER', 'ZAR', 'ZMW', 'ZWG', 'ZWL',
    ];

    /** @var array<string, int>|null */
    private static ?array $units = null;

    public static function exists(string $code): bool
    {
        return isset(self::units()[$code]);
    }

    /** The number of decimal places, e.g. 2 for EUR. */
    public static function minorUnits(string $code): int
    {
        return self::units()[$code] ?? throw new \InvalidArgumentException("\"$code\" is not an ISO 4217 currency code.");
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::units());
    }

    /** @return array<string, int> */
    private static function units(): array
    {
        return self::$units ??= array_fill_keys(self::TWO, 2) + array_fill_keys(self::ZERO, 0) + array_fill_keys(self::THREE, 3) + array_fill_keys(self::FOUR, 4);
    }
}
