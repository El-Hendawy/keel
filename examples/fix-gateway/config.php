<?php

declare(strict_types=1);

/** @var Keel\Runtime\Environment $env */
return [
    'app' => ['key' => getenv('APP_KEY') ?: ''],
    'log' => ['level' => $env->isTest() ? 'warning' : 'info'],
    'fix' => [
        'listen_host' => getenv('FIX_LISTEN_HOST') ?: '127.0.0.1',
        'sessions' => [
            // Clients connect to us.
            'client-acme' => [
                'role' => 'acceptor', 'port' => 9878, 'begin_string' => 'FIX.4.4',
                'sender' => 'VENUE', 'target' => 'ACME', 'heartbeat' => 30,
                'password' => getenv('FIX_ACME_PASSWORD') ?: '',
            ],
        ],
    ],
];
