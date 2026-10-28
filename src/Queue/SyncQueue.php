<?php

declare(strict_types=1);

namespace Keel\Queue;

use Keel\Container\Attribute\Scoped;
use Keel\Container\Disposable;
use Keel\Container\Scope;
use Keel\Tenancy\TenantContext;
use Psr\Log\LoggerInterface;

/**
 * The default queue: runs jobs in-process, each in a fresh scope, when the scope that
 * pushed them ends (after its transaction, like the outbox would). No retries: a failing
 * job is logged. Fine for development; refused in production when the application has
 * handlers.
 */
#[Scoped]
final class SyncQueue implements Queue, Disposable
{
    /** @var list<array{type: string, payload: string, tenant: ?string}> */
    private array $pending = [];

    public function __construct(
        private readonly Scope $scope,
        private readonly JobCodec $codec,
        private readonly JobRunner $runner,
        private readonly TenantContext $tenant,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function push(object $job, int $delaySeconds = 0, ?string $type = null): string
    {
        $type ??= $job::class;
        $this->pending[] = ['type' => $type, 'payload' => $this->codec->encode($type, $job), 'tenant' => $this->tenant->current()];

        return 'sync-' . \count($this->pending);
    }

    public function dispose(): void
    {
        $container = $this->scope->container();
        while (($job = array_shift($this->pending)) !== null) {
            try {
                $this->runner->run($container, $job['type'], $job['payload'], $job['tenant']);
            } catch (\Throwable $e) {
                $this->logger->error('Job {type} failed (SyncQueue does not retry): {error}', ['type' => $job['type'], 'error' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
