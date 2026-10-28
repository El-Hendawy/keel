<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class TooManyRequests extends HttpException
{
    public function __construct(int $retryAfterSeconds, string $detail = 'Rate limit exceeded.')
    {
        parent::__construct(429, $detail, 'Too Many Requests', headers: ['Retry-After' => (string) max(1, $retryAfterSeconds)]);
    }
}
