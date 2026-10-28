<?php

declare(strict_types=1);

namespace Shop\Account;

final readonly class MeResource
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public array $scopes,
        public string $kind,
    ) {
    }
}
