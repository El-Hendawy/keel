<?php

namespace App\Events;

final class OrderPlaced
{
    public function __construct(public string $orderId, public string $tenantId, public int $totalCents)
    {
    }
}
