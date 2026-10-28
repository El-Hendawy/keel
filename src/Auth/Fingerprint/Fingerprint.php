<?php

declare(strict_types=1);

namespace Keel\Auth\Fingerprint;

/**
 * Keyed digests of a client's stable traits, one per component. No raw value (user agent,
 * address) is kept: digests are HMACs under a key derived from `app.key`, so they cannot
 * be reversed or correlated across applications.
 */
final readonly class Fingerprint
{
    /** @param array<string, string> $components component => hex digest; absent traits are omitted */
    public function __construct(public array $components)
    {
    }

    /**
     * The components bound in $this that $current does not match. A component bound
     * here but missing from $current is a mismatch; one not bound here is ignored.
     *
     * @return list<string>
     */
    public function mismatches(self $current): array
    {
        $mismatched = [];
        foreach ($this->components as $name => $digest) {
            if (!isset($current->components[$name]) || !hash_equals($digest, $current->components[$name])) {
                $mismatched[] = $name;
            }
        }

        return $mismatched;
    }

    /** The DPoP key thumbprint this token is bound to, if any (stored with the fingerprint). */
    public function dpop(): ?string
    {
        return $this->components['dpop'] ?? null;
    }

    public function with(string $component, string $value): self
    {
        return new self([$component => $value] + $this->components);
    }

    public function without(string $component): self
    {
        $components = $this->components;
        unset($components[$component]);

        return new self($components);
    }

    public function isEmpty(): bool
    {
        return $this->components === [];
    }

    /** One digest over every component, for tokens that carry a single value (the JWT `cnf.kfp` claim). */
    public function digest(): string
    {
        $components = $this->components;
        ksort($components);

        return hash('sha256', http_build_query($components));
    }

    public function encode(): string
    {
        return json_encode($this->components, \JSON_THROW_ON_ERROR);
    }

    public static function decode(?string $json): self
    {
        if ($json === null || $json === '') {
            return new self([]);
        }
        $data = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        $components = [];
        if (\is_array($data)) {
            foreach ($data as $name => $digest) {
                if (\is_string($name) && \is_string($digest)) {
                    $components[$name] = $digest;
                }
            }
        }

        return new self($components);
    }
}
