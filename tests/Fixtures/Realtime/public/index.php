<?php

declare(strict_types=1);

// FrankenPHP / FPM entry point for the benchmark against `keel run`.
require (getenv('COMPOSER_VENDOR_DIR') ?: __DIR__ . '/../../../../vendor') . '/autoload.php';

Keel\Runtime\Runtime::run(__DIR__ . '/../app.php');
