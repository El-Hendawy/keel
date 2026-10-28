<?php

declare(strict_types=1);

// Mistakes the compiler must catch: authorization without ->withAuth(), a bad cron
// expression, an async listener on an interface, and a queue without a database.
return Keel\Runtime\AppDefinition::create(__DIR__, 'misconfigured')
    ->scan('.')
    ->withQueue()
    ->buildDir(sys_get_temp_dir() . '/keel-tests/misconfigured');
