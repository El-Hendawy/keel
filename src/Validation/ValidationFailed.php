<?php

declare(strict_types=1);

namespace Keel\Validation;

/** Rendered as a 422 problem+json response listing every violation. */
final class ValidationFailed extends \RuntimeException
{
    /** @param list<Violation> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(\count($violations) . ' validation error(s): ' . implode('; ', array_map(
            static fn (Violation $v): string => ($v->path === '' ? '(root)' : $v->path) . ': ' . $v->message,
            $violations,
        )));
    }
}
