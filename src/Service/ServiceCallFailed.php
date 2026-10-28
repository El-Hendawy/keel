<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Http\Exception\HttpException;

/**
 * A call to another service failed: it answered with an error, was unreachable, or the
 * caller's deadline passed. Uncaught, it renders as 502 (504 for timeouts) with the
 * upstream status, so failures stay visible along the call chain.
 */
final class ServiceCallFailed extends HttpException
{
    /** @param array<mixed>|null $problem the upstream problem document, when it sent one */
    public function __construct(
        public readonly string $service,
        public readonly int $upstreamStatus,
        string $detail,
        public readonly ?array $problem = null,
        ?\Throwable $previous = null,
    ) {
        $status = $upstreamStatus === 504 || $upstreamStatus === 0 && $previous instanceof \Keel\Async\TimeoutException ? 504 : 502;
        parent::__construct($status, $detail, $status === 504 ? 'Gateway Timeout' : 'Bad Gateway', extensions: ['service' => $service, 'upstreamStatus' => $upstreamStatus], previous: $previous);
    }
}
