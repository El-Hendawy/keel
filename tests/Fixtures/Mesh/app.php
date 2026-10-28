<?php

declare(strict_types=1);

// One small service, booted twice in the tests (as "orders" and as "billing") to exercise
// service-to-service calls, deadlines and CloudEvents between them.
return Keel\Runtime\AppDefinition::create(__DIR__, 'mesh')
    ->scan('.')
    ->withDatabase()
    ->withQueue()
    ->withAuth()
    ->withServices()
    ->buildDir(sys_get_temp_dir() . '/keel-tests/mesh');
