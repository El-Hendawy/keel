<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/**
 * A payment card number (PAN): 12 to 19 digits passing the Luhn check. Store it only if you
 * must and are PCI DSS scoped: tokenize with your processor, or at least #[Encrypted]; log
 * `CardNumber::mask()` and never the number.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class CardNumber implements Constraint
{
    public static function isValid(string $pan): bool
    {
        if (preg_match('/^\d{12,19}$/', $pan) !== 1) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = \strlen($pan) - 1; $i >= 0; $i--) {
            $digit = (int) $pan[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    /** The first six and last four digits, the rest masked: what PCI DSS allows to display. */
    public static function mask(string $pan): string
    {
        $digits = preg_replace('/\D/', '', $pan) ?? '';
        if (\strlen($digits) < 12) {
            return str_repeat('*', \strlen($digits));
        }

        return substr($digits, 0, 6) . str_repeat('*', \strlen($digits) - 10) . substr($digits, -4);
    }

    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "\\Keel\\Validation\\CardNumber::isValid($value)";
    }

    public function code(): string
    {
        return 'card_number';
    }

    public function message(): string
    {
        return 'Must be a valid card number: 12 to 19 digits.';
    }

    public function schema(): array
    {
        return ['pattern' => '^[0-9]{12,19}$'];
    }
}
