<?php

declare(strict_types=1);

use Keel\Tests\Fixtures\Contracts\FriendlyGreeter;
use Keel\Tests\Fixtures\Contracts\Greeter;

return Keel\Runtime\AppDefinition::create(__DIR__, 'contracts')
    ->scan('.')
    ->bind(Greeter::class, FriendlyGreeter::class)
    ->buildDir(sys_get_temp_dir() . '/keel-tests/contracts');
