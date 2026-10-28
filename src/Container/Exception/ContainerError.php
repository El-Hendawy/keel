<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerError extends RuntimeException implements ContainerExceptionInterface
{
}
