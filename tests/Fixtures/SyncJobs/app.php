<?php

declare(strict_types=1);

// No ->withQueue(): jobs go to the SyncQueue and run when the pushing scope ends.
return Keel\Runtime\AppDefinition::create(__DIR__, 'sync-jobs')
    ->scan('.')
    ->buildDir(sys_get_temp_dir() . '/keel-tests/sync-jobs');
