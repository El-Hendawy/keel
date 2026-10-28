<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class UnsupportedMediaType extends HttpException
{
    /** @param array<string, mixed> $extensions */
    public function __construct(string $detail = '', array $extensions = [], ?\Throwable $previous = null)
    {
        parent::__construct(415, $detail, 'Unsupported Media Type', extensions: $extensions, previous: $previous);
    }
}
