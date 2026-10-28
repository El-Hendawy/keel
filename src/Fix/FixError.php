<?php

declare(strict_types=1);

namespace Keel\Fix;

/** A message that cannot be accepted, with the session-level reject reason (tag 373) when it is a Reject. */
final class FixError extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $rejectReason = null, public readonly ?int $refTag = null)
    {
        parent::__construct($message);
    }
}
