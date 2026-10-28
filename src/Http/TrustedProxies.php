<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the client address. Forwarding headers are ignored unless the direct peer
 * is a proxy you trusted by name in app.php with ->trustProxy([...]).
 */
#[Singleton]
final readonly class TrustedProxies
{
    /** @param list<string> $cidrs */
    public function __construct(#[Config('http.trusted_proxies')] private array $cidrs = [])
    {
    }

    public function clientIp(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $remote = \is_string($remote) ? $remote : '';
        if ($this->cidrs === [] || !$this->isTrusted($remote)) {
            return $remote;
        }

        $forwarded = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));
        // Walk from the closest hop outwards; the first untrusted address is the client.
        foreach (array_reverse($forwarded) as $hop) {
            if ($hop !== '' && filter_var($hop, \FILTER_VALIDATE_IP) !== false && !$this->isTrusted($hop)) {
                return $hop;
            }
        }

        return $remote;
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->cidrs as $cidr) {
            if (self::inRange($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function inRange(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($parts[0]);
        if ($ipBin === false || $subnetBin === false || \strlen($ipBin) !== \strlen($subnetBin)) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : \strlen($ipBin) * 8;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($ipBin[$bytes]) & $mask) === (\ord($subnetBin[$bytes]) & $mask);
    }
}
