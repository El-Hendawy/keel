<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class Unauthorized extends HttpException
{
    /** @param array<string, mixed> $extensions */
    public function __construct(string $detail = '', array $extensions = [], ?\Throwable $previous = null)
    {
        parent::__construct(401, $detail, 'Unauthorized', extensions: $extensions, previous: $previous);
    }
}
