<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class Unauthorized extends HttpException
{
    /**
     * @param array<string, mixed> $extensions
     * @param array<string, string> $headers e.g. WWW-Authenticate
     */
    public function __construct(string $detail = '', array $extensions = [], ?\Throwable $previous = null, array $headers = [])
    {
        parent::__construct(401, $detail, 'Unauthorized', extensions: $extensions, headers: $headers, previous: $previous);
    }
}
