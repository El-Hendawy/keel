<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class PayloadTooLarge extends HttpException
{
    /** @param array<string, mixed> $extensions */
    public function __construct(string $detail = '', array $extensions = [], ?\Throwable $previous = null)
    {
        parent::__construct(413, $detail, 'Payload Too Large', extensions: $extensions, previous: $previous);
    }
}
