<?php

declare(strict_types=1);

namespace Keel\Build;

/**
 * Thrown when the application graph is invalid. Every problem found is reported at
 * once, each with the class it concerns and a suggested fix.
 */
final class BuildFailed extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(
            "The build failed with " . \count($errors) . " error(s):\n\n  - " . implode("\n  - ", $errors),
        );
    }
}
