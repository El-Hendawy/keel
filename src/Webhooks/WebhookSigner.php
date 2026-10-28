<?php

declare(strict_types=1);

namespace Keel\Webhooks;

/**
 * Signatures in the Standard Webhooks format (https://www.standardwebhooks.com):
 * `webhook-signature: v1,<base64 HMAC-SHA256 of "id.timestamp.body">`, keyed with a
 * `whsec_<base64>` secret. Receivers in any language can verify them with existing libraries.
 */
final class WebhookSigner
{
    public static function generateSecret(): string
    {
        return 'whsec_' . base64_encode(random_bytes(32));
    }

    public static function sign(string $id, int $timestamp, string $body, string $secret): string
    {
        return 'v1,' . base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", self::key($secret), true));
    }

    /**
     * Verifies an incoming webhook. Rejects timestamps outside the tolerance (replays).
     *
     * @param array<string, string> $headers lower-case names: webhook-id, webhook-timestamp, webhook-signature
     */
    public static function verify(array $headers, string $body, string $secret, int $now, int $toleranceSeconds = 300): bool
    {
        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        if ($id === '' || preg_match('/^\d+$/', $timestamp) !== 1 || abs($now - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = self::sign($id, (int) $timestamp, $body, $secret);
        foreach (explode(' ', $headers['webhook-signature'] ?? '') as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $secret): string
    {
        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $secret;
    }
}
