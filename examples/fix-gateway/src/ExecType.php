<?php

declare(strict_types=1);

namespace FixGateway;

/** ExecType (150) and OrdStatus (39) share these codes for the states this venue uses. */
enum ExecType: string
{
    case New = '0';
    case Rejected = '8';
}
