<?php

declare(strict_types=1);

// Allows the vendor directory to live outside the repository (COMPOSER_VENDOR_DIR),
// for example when the checkout is on a filesystem Composer cannot extract to.
$vendor = getenv('COMPOSER_VENDOR_DIR') ?: __DIR__ . '/../vendor';
require $vendor . '/autoload.php';
