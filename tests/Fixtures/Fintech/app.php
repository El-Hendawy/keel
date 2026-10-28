<?php

declare(strict_types=1);

// Payments-style endpoints: end-to-end encrypted bodies, money, a ledger, service calls.
return Keel\Runtime\AppDefinition::create(__DIR__, 'fintech')
    ->scan('.')
    ->config('config.php')
    ->withDatabase()
    ->withMessageEncryption()
    ->withLedger()
    ->buildDir(sys_get_temp_dir() . '/keel-tests/fintech');
