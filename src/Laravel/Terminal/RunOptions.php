<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Shirahcan\AiClient\AiClient;

/**
 * How hard the AI calls of one piece of console work run (1.66.0, moved from Portify's AiRunOptions for the
 * shared console, C1a): a LEVEL (normal = the waterfall, pro = Claude Sonnet, expert = Claude Opus; pro and
 * expert fall back to lower levels) and an EFFORT (low, medium, high).
 *
 * A console Build wraps its work in `during()`; every call made inside it runs at that level and effort,
 * applied once by the product at its one AI choke point (`RunOptions::applyTo($client)`). Outside a scope
 * nothing is applied, so every other call in the product is exactly what it was. Scoped like a stream
 * listener and restored in `finally`, so a queue worker never carries it into the next job.
 *
 * Each call reports the level that answered (`served()`); the scope keeps the LOWEST, so a Build where any
 * call fell back says so ("Expert unavailable, Pro answered") instead of claiming the level it asked for.
 */
final class RunOptions
{
    public const LEVELS = ['normal', 'pro', 'expert'];

    public const EFFORTS = ['low', 'medium', 'high'];

    /**
     * The time a call at a higher level or effort may take (found 2026-11-09 in the browser): ai-service's
     * default budget is 30s and it holds half of it back for the last rung, so every earlier rung was cut
     * at ~15s. Opus thinking at medium effort took 17s: the first account was cut off and paid for, the
     * second answered, and the request outlived the browser. A caller's own budget still wins (it is
     * applied after this, on the same clone).
     */
    public const BUDGET_SECONDS = 150.0;

    public const PER_PROVIDER_SECONDS = 120;

    /** @var array{level: ?string, effort: ?string, served: ?string, session: ?string}|null */
    private static ?array $scope = null;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function during(?string $level, ?string $effort, callable $work, ?string $session = null): mixed
    {
        $previous = self::$scope;
        self::$scope = [
            'level' => in_array($level, self::LEVELS, true) ? $level : null,
            'effort' => in_array($effort, self::EFFORTS, true) ? $effort : null,
            'served' => null,
            // The console session the work is for (2026-11-09, /cost): every call is counted under it.
            'session' => is_string($session) && $session !== '' ? $session : null,
        ];

        try {
            return $work();
        } finally {
            self::$scope = $previous;
        }
    }

    /** The scope's level and effort (both null outside a scope). @return array{level: ?string, effort: ?string} */
    public static function current(): array
    {
        return ['level' => self::$scope['level'] ?? null, 'effort' => self::$scope['effort'] ?? null];
    }

    /** The client a call in this scope uses: the level and effort applied, or the client untouched. */
    public static function applyTo(AiClient $client): AiClient
    {
        if (self::$scope === null) {
            return $client;
        }

        // Counted in the console session's cost, by the level asked for (the kit owns the key's format).
        if (self::$scope['session'] !== null) {
            $client = SessionUsage::tag($client, self::$scope['session'], self::$scope['level'] ?? 'normal');
        }

        $slower = false;

        if (self::$scope['level'] !== null && self::$scope['level'] !== 'normal') {
            $client = $client->withLevel(self::$scope['level']);
            $slower = true;
        }

        if (self::$scope['effort'] !== null) {
            $client = $client->withEffort(self::$scope['effort']);
            $slower = $slower || self::$scope['effort'] !== 'low';
        }

        return $slower ? $client->withBudget(self::BUDGET_SECONDS, self::PER_PROVIDER_SECONDS) : $client;
    }

    /** A call in this scope was answered at `$level` (from the service); the lowest one is kept. */
    public static function served(?string $level): void
    {
        if (self::$scope === null || ! in_array($level, self::LEVELS, true)) {
            return;
        }

        $kept = self::$scope['served'];

        if ($kept === null || array_search($level, self::LEVELS, true) < array_search($kept, self::LEVELS, true)) {
            self::$scope['served'] = $level;
        }
    }

    /** The level that answered this scope's calls (the lowest any call reached), or null when none said. */
    public static function servedLevel(): ?string
    {
        return self::$scope['served'] ?? null;
    }
}
