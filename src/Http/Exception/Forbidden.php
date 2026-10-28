<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class Forbidden extends HttpException
{
    /** @param array<string, mixed> $extensions */
    public function __construct(string $detail = '', array $extensions = [], ?\Throwable $previous = null)
    {
        parent::__construct(403, $detail, 'Forbidden', extensions: $extensions, previous: $previous);
    }
}
