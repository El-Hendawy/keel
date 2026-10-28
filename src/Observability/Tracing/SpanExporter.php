<?php

declare(strict_types=1);

namespace Keel\Observability\Tracing;

interface SpanExporter
{
    /** @param list<Span> $spans */
    public function export(array $spans): void;
}
