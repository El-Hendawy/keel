<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Http\RequestContext;
use Keel\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deadline propagation: a caller sends how long it will wait (`Keel-Timeout-Ms`, or gRPC's
 * `grpc-timeout`); the time left is recorded in RequestContext::$deadline, passed on by
 * Services to the next hop, and a request that arrives already expired is answered 504 at once.
 * `services.max_timeout_ms` caps what callers can ask for.
 */
#[Scoped]
final readonly class DeadlineMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestContext $context,
        #[Config('services.max_timeout_ms')] private int $max = 60_000,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $timeout = self::parse($request->getHeaderLine('Keel-Timeout-Ms'), $request->getHeaderLine('grpc-timeout'));
        if ($timeout !== null) {
            if ($timeout <= 0) {
                return Responses::problem(504, 'Gateway Timeout', 'The caller\'s deadline passed before the request was handled.', extensions: ['code' => 'deadline_exceeded']);
            }
            $this->context->deadline = $this->context->startedAt + min($timeout, $this->max) / 1000;
        }

        return $handler->handle($request);
    }

    /** @return int|null milliseconds */
    private static function parse(string $keel, string $grpc): ?int
    {
        if (preg_match('/^-?\d{1,9}$/', $keel) === 1) {
            return (int) $keel;
        }
        if (preg_match('/^(\d{1,8})([HMSmun])$/', $grpc, $m) === 1) {
            $value = (int) $m[1];

            return (int) match ($m[2]) {
                'H' => $value * 3_600_000,
                'M' => $value * 60_000,
                'S' => $value * 1000,
                'm' => $value,
                'u' => intdiv($value, 1000),
                'n' => intdiv($value, 1_000_000),
            };
        }

        return null;
    }
}
