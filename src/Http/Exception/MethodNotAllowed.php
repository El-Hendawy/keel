<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class MethodNotAllowed extends HttpException
{
    /** @param list<string> $allowed */
    public function __construct(array $allowed)
    {
        parent::__construct(405, '', 'Method Not Allowed', headers: ['Allow' => implode(', ', $allowed)]);
    }
}
