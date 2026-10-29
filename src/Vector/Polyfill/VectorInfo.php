<?php

declare(strict_types=1);

namespace Keel\Vector;

/**
 * Reports which implementation of the vectors is loaded: this is the pure-PHP one.
 */
final class VectorInfo
{
    private function __construct()
    {
    }

    /** "php" here; the extension answers "avx2", "sse4.2", "neon" or "scalar". */
    public static function simdLevel(): string
    {
        return 'php';
    }

    /** The extension version this implementation matches. */
    public static function version(): string
    {
        return '0.1.0';
    }

    public static function isNative(): bool
    {
        return false;
    }
}
