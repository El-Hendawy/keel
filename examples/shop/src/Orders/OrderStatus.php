<?php

declare(strict_types=1);

namespace Shop\Orders;

enum OrderStatus: string
{
    case Placed = 'placed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
