<?php

namespace Shirahcan\AiClient\Chat;

/**
 * Checks that a chat-tool call really came from ai-service.
 *
 * The service signs `{timestamp}.{body}` with HMAC-SHA256 and the tool host's
 * shared secret (`X-Ai-Signature`, `X-Ai-Timestamp`). Every product verifies it
 * here, the same way, so no product hand-rolls a comparison that leaks timing
 * or forgets the clock.
 *
 * ⚠ THE CLOCK IS PART OF THE SIGNATURE. A request more than `$skewSeconds` off
 * is refused even when the HMAC matches, so a captured call cannot be replayed
 * later. ⚠ AN EMPTY SECRET VERIFIES NOTHING: it is refused, never treated as
 * "no signing configured, let it through".
 */
final class ToolRequestVerifier
{
    public const DEFAULT_SKEW_SECONDS = 60;

    public static function verify(
        ?string $timestamp,
        ?string $signature,
        string $body,
        string $secret,
        int $skewSeconds = self::DEFAULT_SKEW_SECONDS,
        ?int $now = null,
    ): bool {
        if ($secret === '' || $timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $skewSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }

    /** The headers a signed request carries, for a test or a product calling itself. */
    public static function sign(string $body, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'X-Ai-Timestamp' => (string) $timestamp,
            'X-Ai-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }
}
