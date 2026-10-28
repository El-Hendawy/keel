<?php

declare(strict_types=1);

namespace Keel\Money;

/** How a result between two minor units is rounded. */
enum Rounding
{
    /** To the nearest; ties to the even neighbour (banker's rounding). The default. */
    case HalfEven;
    /** To the nearest; ties away from zero. */
    case HalfUp;
    /** To the nearest; ties toward zero. */
    case HalfDown;
    /** Away from zero. */
    case Up;
    /** Toward zero (truncate). */
    case Down;
    /** Toward positive infinity. */
    case Ceiling;
    /** Toward negative infinity. */
    case Floor;
}
