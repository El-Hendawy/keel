<?php

declare(strict_types=1);

namespace Keel\Webhooks;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;

/**
 * Refuses URLs that point into private networks: loopback, RFC 1918, link-local (which
 * includes cloud metadata at 169.254.169.254), CGNAT, unique-local IPv6 and the like.
 * Customer-supplied webhook URLs are otherwise a server-side request forgery hole.
 *
 * Only https is allowed unless `webhooks.allow_http` is true. Unsafe by name:
 * `webhooks.unsafe_allow_private_networks` turns the network check off (for local development).
 */
#[Singleton]
final readonly class UrlGuard
{
    public function __construct(
        #[Config('webhooks.allow_http')] private bool $allowHttp = false,
        #[Config('webhooks.unsafe_allow_private_networks')] private bool $allowPrivate = false,
    ) {
    }

    /** @throws \InvalidArgumentException when the URL may not be called */
    public function check(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if ($host === '' || !\in_array($scheme, $this->allowHttp ? ['https', 'http'] : ['https'], true)) {
            throw new \InvalidArgumentException("Webhook URL $url must be an absolute " . ($this->allowHttp ? 'http(s)' : 'https') . ' URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URLs must not contain credentials.');
        }
        if ($this->allowPrivate) {
            return;
        }
        $host = trim($host, '[]');
        $addresses = filter_var($host, \FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($addresses === []) {
            throw new \InvalidArgumentException("Webhook host $host does not resolve.");
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                throw new \InvalidArgumentException("Webhook host $host resolves to $address, a private or reserved address.");
            }
        }
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96', '64:ff9b::/96'] as $range) {
            if (\Keel\Http\TrustedProxies::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [];
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return $addresses;
    }
}
