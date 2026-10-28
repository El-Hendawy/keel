<?php

declare(strict_types=1);

namespace Keel\Queue;

use Keel\Container\Attribute\Scoped;
use Keel\Database\Database;
use Keel\Observability\Tracing\Tracer;
use Keel\Support\IdGenerator;
use Keel\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Jobs in the `keel_jobs` table, inserted through the scope's Database: inside a
 * transaction, the job commits or rolls back with your data (transactional outbox).
 * Processed by `keel queue:work`.
 */
#[Scoped]
final readonly class DatabaseQueue implements Queue
{
    public function __construct(
        private Database $db,
        private JobCodec $codec,
        private IdGenerator $ids,
        private ClockInterface $clock,
        private TenantContext $tenant,
        private Tracer $tracer,
    ) {
    }

    public function push(object $job, int $delaySeconds = 0, ?string $type = null): string
    {
        $type ??= $job::class;
        $id = $this->ids->generate();
        $now = $this->clock->now()->getTimestamp();
        $this->db->execute(
            'INSERT INTO keel_jobs (id, queue, type, payload, tenant_id, traceparent, attempts, status, available_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)',
            [$id, $this->codec->definition($type)['queue'], $type, $this->codec->encode($type, $job), $this->tenant->current(), $this->tracer->active()?->traceparent(), 'pending', $now + max(0, $delaySeconds), $now],
        );

        return $id;
    }
}
