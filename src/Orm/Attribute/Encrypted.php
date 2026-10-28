<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/**
 * Stores the column encrypted (AES-256-GCM, `crypto.keys`), bound to its table and column.
 *
 *     #[Encrypted] public string $iban,
 *     #[Encrypted] public ?array $kyc = null,
 *
 * For string and array properties. The entity sees plaintext; the database only ever holds
 * ciphertext, stored as text. Encrypted columns cannot be filtered, sorted, indexed or unique,
 * because equal values encrypt differently.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Encrypted
{
}
