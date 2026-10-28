<?php

declare(strict_types=1);

return Keel\Runtime\AppDefinition::create(__DIR__, 'lifetimes')
    ->scan('.')
    ->buildDir(sys_get_temp_dir() . '/keel-tests/lifetimes');
