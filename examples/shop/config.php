<?php

declare(strict_types=1);

/**
 * Runtime configuration, read once per worker at boot. `$env` is the Keel\Runtime\Environment.
 * Every key a service reads is listed, with its type and default, by `keel inspect`.
 *
 * @var Keel\Runtime\Environment $env
 */
return [
    'app' => [
        'name' => 'shop',
        // 32+ random bytes; `keel key:generate` prints one. Derives fingerprint, cursor and webhook keys.
        'key' => getenv('APP_KEY') ?: '',
    ],
    'database' => [
        'dsn' => getenv('DATABASE_DSN') ?: 'sqlite:' . __DIR__ . '/var/shop.sqlite',
        // Statements on these tables must filter by the current tenant, or they fail.
        'tenant_tables' => ['orders', 'invoices', 'products'],
    ],
    'fx' => [
        'url' => getenv('FX_URL') ?: 'https://api.frankfurter.app/latest',
    ],
    'log' => [
        'level' => $env->isTest() ? 'warning' : 'info',
    ],
    'metrics' => [
        'token' => getenv('METRICS_TOKEN') ?: '',
    ],
    'merchants' => [
        // Where order notifications are sent; empty disables them.
        'webhook_url' => getenv('MERCHANT_WEBHOOK_URL') ?: '',
        'webhook_secret' => getenv('MERCHANT_WEBHOOK_SECRET') ?: '',
    ],
    'redis' => [
        'url' => getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379',
    ],
];
