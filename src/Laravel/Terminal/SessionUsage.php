<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Shirahcan\AiClient\AiClient;

/**
 * What a console session has cost (1.62.0, owner 2026-11-09: "/cost ... so choosing Pro or Expert isn't
 * blind"), read from ai-service, the one authority on AI cost: every call made for the session carries
 * `withCorrelation(['_key' => SessionUsage::key($session, $level)])`, and the service sums them.
 *
 * ⚠ ONE KEY FORMAT, HERE. The product tags its calls with `key()` and this reads them back with the same
 * function, so the two can never disagree. Grouped by the LEVEL the ask was made at (owner: name the level,
 * never the model); a fallback to a lower level is still counted under the level asked for.
 */
final class SessionUsage
{
    public const LEVELS = ['normal', 'pro', 'expert'];

    public function __construct(private readonly AiClient $ai) {}

    /** The correlation key a session's calls at one level carry (at most 80 characters, ai-service's cap). */
    public static function key(string $sessionId, ?string $level = null): string
    {
        return 'terminal:'.$sessionId.':'.(in_array($level, self::LEVELS, true) ? $level : 'normal');
    }

    /** The client a session's calls use: tagged with the session and the level. */
    public static function tag(AiClient $client, string $sessionId, ?string $level = null): AiClient
    {
        return $client->withCorrelation(['_key' => self::key($sessionId, $level), 'session' => $sessionId]);
    }

    /**
     * @param  ?string  $since  the session's start (ISO 8601); the service counts from it
     * @return array{levels: list<array{level: string, calls: int, tokens_in: int, tokens_out: int, cost_usd: float}>, total: array{calls: int, tokens_in: int, tokens_out: int, cost_usd: float}, since: ?string}
     */
    public function of(string $sessionId, ?string $since): array
    {
        // The service counts from the start of the month by default: a session can be older.
        $from = $since ?? now()->subDays(90)->toIso8601String();
        $levels = [];

        foreach (self::LEVELS as $level) {
            $totals = (array) ($this->ai->usage(['correlation_key' => self::key($sessionId, $level), 'from' => $from])['totals'] ?? []);
            $levels[] = [
                'level' => $level,
                'calls' => (int) ($totals['calls'] ?? 0),
                'tokens_in' => (int) ($totals['tokens_in'] ?? 0),
                'tokens_out' => (int) ($totals['tokens_out'] ?? 0),
                'cost_usd' => round(((int) ($totals['spend_micros'] ?? 0)) / 1_000_000, 4),
            ];
        }

        $sum = static fn (string $key) => array_sum(array_column($levels, $key));

        return [
            'levels' => $levels,
            'total' => ['calls' => (int) $sum('calls'), 'tokens_in' => (int) $sum('tokens_in'), 'tokens_out' => (int) $sum('tokens_out'), 'cost_usd' => round((float) $sum('cost_usd'), 4)],
            'since' => $since,
        ];
    }
}
