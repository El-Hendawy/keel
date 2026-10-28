<?php

declare(strict_types=1);

namespace FixGateway;

use Keel\Fix\Attribute\Field;

/** One instance of the Parties repeating group (NoPartyIDs, 453). */
final readonly class Party
{
    public function __construct(
        #[Field(448)] public string $partyId,
        #[Field(447)] public string $source = 'D',
        #[Field(452)] public int $role = 3,
    ) {
    }
}
