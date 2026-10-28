<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Http\Exception\BadRequest;
use Keel\Http\Exception\PayloadTooLarge;
use Keel\Http\Exception\UnsupportedMediaType;
use Psr\Http\Message\ServerRequestInterface;

/** Decodes request bodies for generated endpoints. JSON only, with size and depth limits. */
final class RequestBody
{
    public const int MAX_DEPTH = 64;

    public static function decode(ServerRequestInterface $request, int $maxBytes = 1_048_576): mixed
    {
        $parsed = $request->getParsedBody();
        if (\is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $stream = $request->getBody();
        $size = $stream->getSize();
        if ($size !== null && $size > $maxBytes) {
            throw new PayloadTooLarge("Request bodies are limited to $maxBytes bytes.");
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = $stream->read($maxBytes + 1);
        if (\strlen($raw) > $maxBytes) {
            throw new PayloadTooLarge("Request bodies are limited to $maxBytes bytes.");
        }
        if (trim($raw) === '') {
            return [];
        }

        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if ($contentType !== '' && !str_contains($contentType, 'json')) {
            throw new UnsupportedMediaType('Send the request body as application/json.');
        }

        try {
            return json_decode($raw, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new BadRequest('The request body is not valid JSON: ' . $e->getMessage() . '.', previous: $e);
        }
    }
}
