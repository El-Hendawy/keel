<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

final readonly class InvoiceInput
{
    public function __construct(public string $orderId)
    {
    }
}
