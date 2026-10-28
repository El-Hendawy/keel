<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Runtime\Environment;
use Psr\Log\LoggerInterface;

/**
 * Counts queries per scope and detects N+1 patterns: the same statement executed more
 * than `database.n_plus_one_threshold` times in one request. In tests that is an
 * exception; elsewhere a warning with the statement.
 */
#[Scoped]
final class QueryLog
{
    /** @var array<string, int> */
    private array $counts = [];

    private int $total = 0;

    private float $totalMs = 0.0;

    public function __construct(
        private readonly Environment $environment,
        private readonly LoggerInterface $logger,
        #[Config('database.n_plus_one_threshold')] private readonly int $threshold = 10,
    ) {
    }

    public function record(string $sql, float $durationMs): void
    {
        $this->total++;
        $this->totalMs += $durationMs;
        $count = $this->counts[$sql] = ($this->counts[$sql] ?? 0) + 1;

        if ($count === $this->threshold + 1) {
            $message = "Possible N+1 query: executed $count times in one scope. Load the rows in one query instead: $sql";
            if ($this->environment->isTest()) {
                throw new NPlusOneDetected($message);
            }
            $this->logger->warning($message, ['sql' => $sql, 'count' => $count]);
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    public function totalMs(): float
    {
        return $this->totalMs;
    }
}
