<?php

// Router script for `keel serve` (PHP's built-in server). Not for production.

declare(strict_types=1);

$autoload = getenv('KEEL_AUTOLOAD');
require is_string($autoload) && $autoload !== '' ? $autoload : getcwd() . '/vendor/autoload.php';

$app = getenv('KEEL_APP');
Keel\Runtime\Runtime::classic(is_string($app) && $app !== '' ? $app : getcwd() . '/app.php');
