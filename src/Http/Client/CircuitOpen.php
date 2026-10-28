<?php

declare(strict_types=1);

namespace Keel\Http\Client;

use Psr\Http\Client\ClientExceptionInterface;

/** Thrown without making a call when a dependency has been failing; it fails fast instead of piling up. */
final class CircuitOpen extends \RuntimeException implements ClientExceptionInterface
{
}
