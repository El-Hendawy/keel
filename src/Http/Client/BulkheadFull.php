<?php

declare(strict_types=1);

namespace Keel\Http\Client;

use Psr\Http\Client\ClientExceptionInterface;

/** Too many concurrent calls to one host from this worker. */
final class BulkheadFull extends \RuntimeException implements ClientExceptionInterface
{
}
