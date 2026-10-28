<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\HttpException;
use Keel\Runtime\Environment;
use Keel\Validation\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns exceptions into RFC 9457 problem responses.
 *
 * Client errors carry their message. Server errors are logged with the full exception
 * and return a generic body, unless debug is on.
 */
#[Singleton]
final readonly class ErrorRenderer
{
    public function __construct(
        private LoggerInterface $logger,
        private Environment $environment,
    ) {
    }

    public function render(\Throwable $error, ?string $requestId = null): ResponseInterface
    {
        $extensions = $requestId !== null && $requestId !== '' ? ['requestId' => $requestId] : [];

        if ($error instanceof ValidationFailed) {
            return Responses::problem(
                422,
                'Validation failed',
                'The request contains invalid data.',
                extensions: ['errors' => $error->violations] + $extensions,
            );
        }

        if ($error instanceof HttpException) {
            if ($error->status >= 500) {
                $this->logger->error($error->getMessage(), ['exception' => $error]);
            }

            return Responses::problem(
                $error->status,
                $error->title !== '' ? $error->title : 'Error',
                $error->getMessage(),
                $error->type,
                $error->extensions + $extensions,
                $error->headers,
            );
        }

        $this->logger->error('Unhandled ' . $error::class . ': ' . $error->getMessage(), ['exception' => $error]);

        if ($this->environment->debug) {
            $extensions['exception'] = [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile() . ':' . $error->getLine(),
                'trace' => \array_slice(explode("\n", $error->getTraceAsString()), 0, 20),
            ];
        }

        return Responses::problem(500, 'Internal Server Error', 'An unexpected error occurred.', extensions: $extensions);
    }
}
