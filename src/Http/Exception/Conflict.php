<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class Conflict extends HttpException
{
    /** @param array<string, mixed> $extensions */
    public function __construct(string $detail = '', array $extensions = [], ?\Throwable $previous = null)
    {
        parent::__construct(409, $detail, 'Conflict', extensions: $extensions, previous: $previous);
    }
}
