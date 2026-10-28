<?php

declare(strict_types=1);

namespace Keel\Tenancy;

/** A statement that could read or write another tenant's data. Always a bug: never caught by the framework. */
final class TenantViolation extends \LogicException
{
}
