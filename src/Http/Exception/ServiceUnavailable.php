<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class ServiceUnavailable extends HttpException
{
    public function __construct(string $detail = '', int $retryAfterSeconds = 1)
    {
        parent::__construct(503, $detail, 'Service Unavailable', headers: ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
