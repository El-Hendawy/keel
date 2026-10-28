<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

// FrankenPHP (worker or classic), RoadRunner and FPM all start here.
Keel\Runtime\Runtime::run(__DIR__ . '/../app.php');
