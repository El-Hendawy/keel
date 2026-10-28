<?php

declare(strict_types=1);

namespace Keel\Http\Streaming;

/** The client went away while a streaming response was being written. Producers stop on it. */
final class ClientDisconnected extends \RuntimeException
{
}
