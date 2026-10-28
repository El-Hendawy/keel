<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

/**
 * An error with a meaning for the client. Rendered as RFC 9457 problem details;
 * the message is the public `detail`, so never put secrets in it.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string, mixed> $extensions extra members added to the problem document */
    public function __construct(
        public readonly int $status,
        string $detail = '',
        public readonly string $title = '',
        public readonly string $type = 'about:blank',
        public readonly array $extensions = [],
        /** @var array<string, string> */
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, $status, $previous);
    }
}
