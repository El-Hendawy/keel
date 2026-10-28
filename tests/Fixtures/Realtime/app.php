<?php

declare(strict_types=1);

// Endpoints exercising the Keel server: concurrency, streaming, WebSockets and the hub.
return Keel\Runtime\AppDefinition::create(__DIR__, 'realtime')
    ->scan('.')
    ->config('config.php')
    ->withDatabase()
    ->buildDir(sys_get_temp_dir() . '/keel-tests/realtime');
