<?php

declare(strict_types=1);

namespace Keel\Queue;

use Keel\Container\Attribute\Singleton;
use Keel\Container\Container;
use Keel\Observability\Metrics\Metrics;
use Keel\Observability\Tracing\Span;
use Keel\Observability\Tracing\Tracer;
use Keel\Tenancy\TenantContext;

/**
 * Runs one job in its own scope, like a request: scoped services are fresh, the job's
 * tenant becomes the current tenant, and the trace continues from the code that pushed it.
 */
#[Singleton]
final readonly class JobRunner
{
    public function __construct(
        private JobCodec $codec,
        private Tracer $tracer,
        private Metrics $metrics,
    ) {
    }

    public function run(Container $container, string $type, string $payload, ?string $tenantId = null, ?string $traceparent = null, int $attempt = 1): void
    {
        $definition = $this->codec->definition($type);
        $started = microtime(true);
        $span = $this->tracer->startSpan('job ' . $type, ['messaging.operation' => 'process', 'messaging.message.attempt' => $attempt], Span::KIND_CONSUMER, $traceparent);
        $scope = $container->createScope();
        $status = 'failed';
        try {
            if ($tenantId !== null) {
                $scope->get(TenantContext::class)->set($tenantId);
            }
            $job = $this->codec->decode($type, $payload);
            $handler = $scope->get($definition['handler']);
            \assert(\is_callable($handler));
            $handler($job);
            $status = 'succeeded';
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->error = true;
            throw $e;
        } finally {
            try {
                $scope->dispose();
            } finally {
                $span->end();
                $this->metrics->increment('jobs_processed_total', ['type' => $type, 'status' => $status], help: 'Jobs processed.');
                $this->metrics->observe('job_duration_seconds', microtime(true) - $started, ['type' => $type], help: 'Job run time.');
            }
        }
    }
}
