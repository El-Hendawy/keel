<?php

declare(strict_types=1);

// Entities exercising every ORM mapping; the schema is created from them in the tests.
return Keel\Runtime\AppDefinition::create(__DIR__, 'orm-blog')
    ->scan('.')
    ->withDatabase()
    ->buildDir(sys_get_temp_dir() . '/keel-tests/orm-blog');
