<?php

declare(strict_types=1);

namespace Keel\Fix;

/**
 * One FIX session, from `fix.sessions` in config.php:
 *
 *     'fix' => ['sessions' => [
 *         'broker' => [
 *             'role' => 'initiator', 'host' => 'fix.broker.example', 'port' => 9878,
 *             'begin_string' => 'FIX.4.4', 'sender' => 'ACME', 'target' => 'BROKER',
 *             'heartbeat' => 30, 'username' => 'acme', 'password' => getenv('FIX_PASSWORD'),
 *         ],
 *     ]],
 */
final readonly class SessionSettings
{
    public function __construct(
        public string $id,
        public string $role,
        public string $beginString,
        public string $sender,
        public string $target,
        public int $heartbeat = 30,
        public string $host = '127.0.0.1',
        public int $port = 9878,
        public bool $resetOnLogon = false,
        public string $username = '',
        public string $password = '',
        public string $defaultApplVerId = '9',
        public int $maxClockSkewSeconds = 120,
    ) {
        if (!\in_array($role, ['initiator', 'acceptor'], true)) {
            throw new \InvalidArgumentException("FIX session $id: role must be initiator or acceptor.");
        }
        if ($sender === '' || $target === '') {
            throw new \InvalidArgumentException("FIX session $id: sender and target CompIDs are required.");
        }
    }

    /** @param array<mixed> $config */
    public static function fromConfig(string $id, array $config): self
    {
        $s = static fn (string $key, string $default = ''): string => \is_scalar($config[$key] ?? null) ? (string) $config[$key] : $default;

        return new self(
            $id,
            $s('role', 'acceptor'),
            $s('begin_string', 'FIX.4.4'),
            $s('sender'),
            $s('target'),
            (int) $s('heartbeat', '30'),
            $s('host', '127.0.0.1'),
            (int) $s('port', '9878'),
            filter_var($s('reset_on_logon', '0'), \FILTER_VALIDATE_BOOL),
            $s('username'),
            $s('password'),
            $s('default_appl_ver_id', '9'),
            (int) $s('max_clock_skew_seconds', '120'),
        );
    }

    public function isFixt(): bool
    {
        return $this->beginString === 'FIXT.1.1';
    }
}
