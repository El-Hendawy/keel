<?php

declare(strict_types=1);

namespace Keel\Async;

/** Thrown inside a task at its suspension point when the task is cancelled. */
class CancelledException extends \RuntimeException
{
}
