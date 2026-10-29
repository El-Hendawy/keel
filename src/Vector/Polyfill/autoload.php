<?php

declare(strict_types=1);

// Loaded by Composer (autoload.files). Without the keel_vector extension, the Keel\Vector classes come
// from the PHP files next to this one, under the same names, so code, serialized data and error
// messages do not depend on which implementation runs. These files are not PSR-4 paths: the
// directory is excluded from Composer's class map.
if (!extension_loaded('keel_vector')) {
    $classes = [
        'Keel\Vector\IntVector' => 'IntVector.php',
        'Keel\Vector\FloatVector' => 'FloatVector.php',
        'Keel\Vector\VectorIterator' => 'VectorIterator.php',
        'Keel\Vector\VectorInfo' => 'VectorInfo.php',
        'Keel\Vector\VectorException' => 'VectorException.php',
        'Keel\Vector\OverflowException' => 'OverflowException.php',
        'Keel\Vector\LengthMismatchException' => 'LengthMismatchException.php',
    ];
    spl_autoload_register(static function (string $class) use ($classes): void {
        if (isset($classes[$class])) {
            require __DIR__ . '/' . $classes[$class];
        }
    });
}
