<?php

declare(strict_types=1);

namespace Keel\Observability\Tracing;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class NullSpanExporter implements SpanExporter
{
    public function export(array $spans): void
    {
    }
}
