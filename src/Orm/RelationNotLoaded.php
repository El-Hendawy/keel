<?php

declare(strict_types=1);

namespace Keel\Orm;

/** A relation was read without being eager-loaded. Always a bug; never caught for you. */
final class RelationNotLoaded extends \LogicException
{
}
