<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Database\Database;
use Keel\Scheduling\Schedule;
use Keel\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Logs yesterday's order count across all tenants, every day at 03:00 UTC. */
#[Schedule('0 3 * * *')]
final readonly class ReportDailyOrders
{
    public function __construct(
        private Database $db,
        private TenantContext $tenant,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $since = $this->clock->now()->modify('-1 day')->format(\DATE_RFC3339_EXTENDED);
        // Deliberately cross-tenant: an aggregate for operators, with no tenant data in the output.
        $count = $this->tenant->acrossTenants(
            fn (): int => $this->db->selectOne('SELECT COUNT(*) AS n FROM orders WHERE created_at >= ?', [$since])?->int('n') ?? 0,
        );
        $this->logger->info('Orders in the last day: {count}', ['count' => $count]);
    }
}
