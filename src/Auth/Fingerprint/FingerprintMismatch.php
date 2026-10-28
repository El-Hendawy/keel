<?php

declare(strict_types=1);

namespace Keel\Auth\Fingerprint;

/**
 * Dispatched when a bound token is presented by a client that does not match it: a
 * likely stolen credential. Listen for it to alert the user or a security team.
 */
final readonly class FingerprintMismatch
{
    /** @param list<string> $components the components that differed */
    public function __construct(
        public string $tokenId,
        public string $principalId,
        public ?string $tenantId,
        public array $components,
        public string $clientIp,
        public bool $enforced,
        public bool $revoked,
    ) {
    }
}
