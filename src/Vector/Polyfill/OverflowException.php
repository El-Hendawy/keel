<?php

declare(strict_types=1);

namespace Keel\Vector;

/**
 * An integer result that does not fit in 64 bits.
 */
final class OverflowException extends VectorException
{
}
