<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/**
 * An IBAN in electronic form (no spaces, upper case): the country's length and the ISO 7064
 * mod 97-10 check digits. Countries outside the registry list are checked by digits alone.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Iban implements Constraint
{
    private const array LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22,
        'BI' => 27, 'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DJ' => 27,
        'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FK' => 18, 'FO' => 18, 'FR' => 27,
        'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32,
        'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'LY' => 25, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19,
        'MN' => 20, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NI' => 28, 'NL' => 18, 'NO' => 15, 'OM' => 23, 'PK' => 24,
        'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'RU' => 33, 'SA' => 24, 'SC' => 31,
        'SD' => 18, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'SO' => 23, 'ST' => 25, 'SV' => 28, 'TL' => 23,
        'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    public static function isValid(string $iban): bool
    {
        if (preg_match('/^([A-Z]{2})\d{2}[A-Z0-9]{11,30}$/', $iban, $m) !== 1) {
            return false;
        }
        if (\strlen($iban) !== (self::LENGTHS[$m[1]] ?? \strlen($iban))) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (\ord($char) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "\\Keel\\Validation\\Iban::isValid($value)";
    }

    public function code(): string
    {
        return 'iban';
    }

    public function message(): string
    {
        return 'Must be a valid IBAN, upper case without spaces.';
    }

    public function schema(): array
    {
        return ['pattern' => '^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$', 'examples' => ['DE89370400440532013000']];
    }
}
