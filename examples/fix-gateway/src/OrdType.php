<?php

declare(strict_types=1);

namespace FixGateway;

enum OrdType: string
{
    case Market = '1';
    case Limit = '2';
}
