<?php

declare(strict_types=1);

namespace Keel\Pagination;

use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\BadRequest;
use Keel\Security\Keys;

/**
 * Opaque, signed cursors for keyset pagination.
 *
 * A cursor carries the sort key of the last row of a page (e.g. created_at and id). It is
 * signed with a key derived from `app.key`, so clients cannot forge one to start at an
 * arbitrary position, and it is bound to a purpose label (usually the endpoint) so a
 * cursor from one list cannot be replayed against another.
 *
 *     $after = $query->cursor === null ? null : $cursors->decode($query->cursor, 'orders');
 *     ... WHERE (created_at, id) < (?, ?) ORDER BY created_at DESC, id DESC LIMIT $limit + 1
 *     $next = $hasMore ? $cursors->encode(['c' => $last->createdAt, 'i' => $last->id], 'orders') : null;
 */
#[Singleton]
final readonly class Cursors
{
    public function __construct(private Keys $keys)
    {
    }

    /** @param array<string, scalar|null> $position */
    public function encode(array $position, string $purpose): string
    {
        $payload = self::b64(json_encode($position, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));

        return $payload . '.' . self::b64(substr($this->mac($payload, $purpose), 0, 16));
    }

    /**
     * @return array<string, scalar|null>
     * @throws BadRequest for a malformed, forged or foreign cursor
     */
    public function decode(string $cursor, string $purpose): array
    {
        $parts = explode('.', $cursor);
        if (\count($parts) !== 2 || !hash_equals(self::b64(substr($this->mac($parts[0], $purpose), 0, 16)), $parts[1])) {
            throw new BadRequest('The cursor is invalid. Use the nextCursor value from a previous page.');
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true, 4);
        if (!\is_array($data)) {
            throw new BadRequest('The cursor is invalid. Use the nextCursor value from a previous page.');
        }
        $position = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && (\is_scalar($value) || $value === null)) {
                $position[$key] = $value;
            }
        }

        return $position;
    }

    private function mac(string $payload, string $purpose): string
    {
        return hash_hmac('sha256', $purpose . "\0" . $payload, $this->keys->derive('cursor'), true);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
