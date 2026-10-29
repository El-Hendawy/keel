<?php

declare(strict_types=1);

// IntVector and FloatVector as API types: hydrated from JSON arrays, serialized back, documented.
return Keel\Runtime\AppDefinition::create(__DIR__, 'vectors')
    ->scan('.')
    ->buildDir(sys_get_temp_dir() . '/keel-tests/vectors');
