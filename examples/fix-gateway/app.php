<?php

declare(strict_types=1);

use Keel\Runtime\AppDefinition;

// A minimal FIX venue: accepts NewOrderSingle (D) and answers with an ExecutionReport (8).
// Run it with `keel fix:run`; sessions are in config.php.
return AppDefinition::create(__DIR__, 'fix-gateway')
    ->scan('src')
    ->config('config.php');
