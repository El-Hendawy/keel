<?php

declare(strict_types=1);

namespace FixGateway;

use Keel\Fix\Attribute\FixHandler;
use Keel\Fix\FixContext;

/** Initiator side: keeps the execution reports it receives (used by the end-to-end test). */
#[FixHandler]
final readonly class RecordExecution
{
    public function __construct(private Blotter $blotter)
    {
    }

    public function __invoke(ExecutionReport $report, FixContext $fix): void
    {
        $this->blotter->reports[] = $report;
    }
}
