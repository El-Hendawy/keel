<?php

declare(strict_types=1);

/**
 * Runtime configuration, read once per worker at boot. `$env` is the Keel\Runtime\Environment.
 * Every key a service reads is listed, with its type and default, by `keel inspect`.
 *
 * @var Keel\Runtime\Environment $env
 */
return [
    'app' => ['name' => 'shop'],
    'database' => [
        'dsn' => getenv('DATABASE_DSN') ?: 'sqlite:' . __DIR__ . '/var/shop.sqlite',
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
    'redis' => [
        'url' => getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379',
    ],
];
