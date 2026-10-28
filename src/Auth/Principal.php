<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Who is calling: a user, an API key or another service.
 *
 * Scopes are plain strings such as `orders:read`. A scope ending in `:*` grants every
 * scope with that prefix, and `*` grants everything.
 */
final readonly class Principal
{
    public const string USER = 'user';
    public const string API_KEY = 'api_key';
    public const string SERVICE = 'service';

    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $claims extra, verified claims (JWT) or token metadata
     */
    public function __construct(
        public string $id,
        public ?string $tenantId = null,
        public array $scopes = [],
        public string $kind = self::USER,
        public ?string $tokenId = null,
        public array $claims = [],
    ) {
    }

    public function hasScope(string $scope): bool
    {
        foreach ($this->scopes as $granted) {
            if ($granted === $scope || $granted === '*') {
                return true;
            }
            if (str_ends_with($granted, ':*') && str_starts_with($scope, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $scopes
     * @return list<string> the scopes from $scopes this principal lacks
     */
    public function missingScopes(array $scopes): array
    {
        return array_values(array_filter($scopes, fn (string $scope): bool => !$this->hasScope($scope)));
    }
}
