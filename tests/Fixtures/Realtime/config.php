<?php

declare(strict_types=1);

return [
    'log' => ['level' => 'error'],
    'database' => ['dsn' => getenv('DATABASE_DSN') ?: 'sqlite::memory:'],
    'rate_limit' => ['enabled' => false],
    'realtime' => ['redis' => (bool) getenv('KEEL_TEST_REDIS_URL')],
    'redis' => ['url' => getenv('KEEL_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379'],
];
