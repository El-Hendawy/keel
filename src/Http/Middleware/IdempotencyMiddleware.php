<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\BadRequest;
use Keel\Http\Exception\Conflict;
use Keel\Http\Exception\UnprocessableEntity;
use Keel\Http\Idempotency\IdempotencyStore;
use Keel\Http\Idempotency\StoredResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Honours the Idempotency-Key header on POST and PATCH.
 *
 * The first request with a key runs normally and its response is stored. A retry with
 * the same key and payload gets the stored response (with `Idempotent-Replayed: true`).
 * A retry while the first is still running gets 409; the same key with a different
 * payload gets 422. Server errors are not stored, so the client can retry them.
 */
#[Singleton]
final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private IdempotencyStore $store,
        #[Config('idempotency.ttl_seconds')] private int $ttl = 86_400,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $key = $request->getHeaderLine('Idempotency-Key');
        if ($key === '' || ($method !== 'POST' && $method !== 'PATCH')) {
            return $handler->handle($request);
        }
        if (preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            throw new BadRequest('Idempotency-Key must be 1 to 255 visible ASCII characters.');
        }

        $body = $request->getBody();
        $payload = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $storeKey = hash('sha256', $method . ' ' . $request->getUri()->getPath() . ' ' . $request->getHeaderLine('Authorization') . ' ' . $key);
        $fingerprint = hash('sha256', $payload);

        $existing = $this->store->claim($storeKey, $fingerprint, $this->ttl);
        if ($existing !== null) {
            if (!hash_equals($existing->fingerprint, $fingerprint)) {
                throw new UnprocessableEntity('This Idempotency-Key was already used with a different request body.');
            }
            if ($existing->response === null) {
                throw new Conflict('A request with this Idempotency-Key is still being processed.');
            }

            return $existing->response->toResponse()->withHeader('Idempotent-Replayed', 'true');
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $this->store->release($storeKey);
            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->store->release($storeKey);
        } else {
            $this->store->complete($storeKey, $fingerprint, StoredResponse::from($response), $this->ttl);
        }

        return $response;
    }
}
