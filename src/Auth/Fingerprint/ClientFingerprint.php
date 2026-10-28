<?php

declare(strict_types=1);

namespace Keel\Auth\Fingerprint;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\TrustedProxies;
use Keel\Security\Keys;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Computes the fingerprint a token is bound to.
 *
 * Components (choose with `auth.fingerprint.components`):
 *  - `user_agent`       the client software, with version numbers removed so browser
 *                       updates do not break sessions
 *  - `accept_language`  the primary language tag
 *  - `tls`              a TLS client fingerprint (JA4) set by your TLS-terminating proxy
 *                       in `auth.fingerprint.tls_header`; only read from trusted proxies
 *  - `ip_prefix`        the client's /24 (IPv4) or /48 (IPv6) network; off by default
 *                       because mobile clients change networks
 *
 * Binding raises the cost of using a stolen token: the thief must also reproduce the
 * victim's client traits. It is not proof of possession; see docs/guide/auth.md.
 */
#[Singleton]
final readonly class ClientFingerprint
{
    public const array COMPONENTS = ['user_agent', 'accept_language', 'tls', 'ip_prefix'];

    /** @param list<string> $components */
    public function __construct(
        private Keys $keys,
        private TrustedProxies $proxies,
        #[Config('auth.fingerprint.components')] private array $components = ['user_agent', 'accept_language', 'tls'],
        #[Config('auth.fingerprint.tls_header')] private string $tlsHeader = 'X-JA4-Fingerprint',
    ) {
        foreach ($components as $component) {
            if (!\in_array($component, self::COMPONENTS, true)) {
                throw new \InvalidArgumentException("Unknown fingerprint component \"$component\" in auth.fingerprint.components. Use: " . implode(', ', self::COMPONENTS) . '.');
            }
        }
    }

    public function of(ServerRequestInterface $request): Fingerprint
    {
        $key = $this->keys->derive('fingerprint');
        $digests = [];
        foreach ($this->components as $component) {
            $value = match ($component) {
                'user_agent' => self::userAgent($request->getHeaderLine('User-Agent')),
                'accept_language' => self::language($request->getHeaderLine('Accept-Language')),
                'tls' => $this->tls($request),
                default => self::ipPrefix($this->proxies->clientIp($request)),
            };
            if ($value !== '') {
                $digests[$component] = substr(hash_hmac('sha256', $component . "\0" . $value, $key), 0, 32);
            }
        }

        return new Fingerprint($digests);
    }

    /** `Mozilla/5.0 (Macintosh) Chrome/131.0.6778.86` -> `mozilla/# (macintosh) chrome/#` */
    public static function userAgent(string $userAgent): string
    {
        $normalized = strtolower(trim($userAgent));
        $normalized = (string) preg_replace('/\d+(?:[._]\d+)*/', '#', $normalized);

        return (string) preg_replace('/\s+/', ' ', $normalized);
    }

    public static function language(string $acceptLanguage): string
    {
        $first = strtolower(trim(explode(',', $acceptLanguage)[0]));
        $first = trim(explode(';', $first)[0]);

        return explode('-', $first)[0];
    }

    public static function ipPrefix(string $ip): string
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return '';
        }

        return \strlen($binary) === 4
            ? bin2hex(substr($binary, 0, 3)) . '/24'
            : bin2hex(substr($binary, 0, 6)) . '/48';
    }

    private function tls(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!\is_string($remote) || !$this->proxies->isTrusted($remote)) {
            return ''; // a client could set the header itself
        }

        return trim($request->getHeaderLine($this->tlsHeader));
    }
}
