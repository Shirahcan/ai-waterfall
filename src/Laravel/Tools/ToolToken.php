<?php

namespace Shirahcan\AiClient\Laravel\Tools;

use Illuminate\Support\Facades\Crypt;

/**
 * The per-turn token a product hands ai-service with a chat turn (`toolToken`), which the
 * engine carries back on every tool call (`X-Ai-Tool-Token`) and only the product can open.
 *
 * ⚠ SHORT-LIVED: five minutes, enough for one turn's loop. A leaked token from a log or a
 * transcript is useless by the time anyone reads it. A job that resumes a deferred turn
 * issues a fresh one.
 */
final class ToolToken
{
    public const TTL_SECONDS = 300;

    public static function issue(ToolActor $actor, ?int $now = null): string
    {
        return Crypt::encryptString((string) json_encode([
            'u'   => $actor->userId,
            'g'   => $actor->guest,
            'a'   => $actor->audience,
            'ka'  => $actor->knowledgeAudience,
            'r'   => $actor->route,
            'et'  => $actor->entityType,
            'ei'  => $actor->entityId,
            'tz'  => $actor->timezone,
            'c'   => $actor->conversationId,
            'x'   => $actor->extras,
            'exp' => ($now ?? time()) + self::TTL_SECONDS,
        ]));
    }

    /** The actor the token names, or null when it is forged, tampered with or expired. */
    public static function open(?string $token, ?int $now = null): ?ToolActor
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $claims = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($claims) || (int) ($claims['exp'] ?? 0) < ($now ?? time())) {
            return null;
        }

        $string = static fn (string $k): ?string => is_scalar($claims[$k] ?? null) ? (string) $claims[$k] : null;
        $actor = new ToolActor(
            userId: $string('u'),
            audience: $string('a') ?? 'client',
            route: $string('r') ?? '/',
            knowledgeAudience: $string('ka'),
            entityType: $string('et'),
            entityId: $string('ei'),
            timezone: $string('tz'),
            conversationId: $string('c'),
            guest: $string('g'),
            extras: is_array($claims['x'] ?? null) ? $claims['x'] : [],
        );

        // A token names a user or is a guest's; one that is neither is not a token this kit made.
        return $actor->userId !== null || $actor->isGuest() ? $actor : null;
    }
}
