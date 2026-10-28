<?php

declare(strict_types=1);

namespace Keel\Orm;

use Keel\Http\Exception\HttpException;

/** Optimistic locking failed: someone else changed the row since it was read. Rendered as 409. */
final class StaleEntity extends HttpException
{
    public function __construct(string $detail)
    {
        parent::__construct(409, $detail, 'Conflict');
    }
}
