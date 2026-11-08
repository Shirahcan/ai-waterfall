<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Shirahcan\AiClient\AiClient;

/**
 * `/compact` for a console session (1.58.0): fold turns into the session's running summary, so the
 * next ask carries a paragraph instead of the whole history.
 *
 * ⚠ EVERY CALL HAS A READER. It runs when the person asks (`/compact`, "Summarise up to / from here")
 * or when their next ask would not fit the context (`SessionWindow::needsCompaction`). Never on a timer.
 *
 * ⚠ NOTHING IS DELETED. Folded turns stay in the transcript and on screen; they only leave the window.
 * The summary records WHICH turns it covers, so a later fold extends it rather than re-summarising.
 */
final class SessionCompactor
{
    /** ai-service's SHARED prompt (`resources/ai-tasks/terminal`), published on every ai-service deploy. */
    public const TASK = 'shared.terminal.compact';

    /** Folded turn ids kept with the summary: the most a session ever reads (`TurnsMax`). */
    private const FOLDED_MAX = TerminalSessions::TURNS_MAX;

    public function __construct(private readonly AiClient $ai, private readonly TerminalSessions $sessions) {}

    /**
     * Fold turns into the summary and save it on the session.
     *
     * `$range`: `older` folds everything but the recent pairs (`/compact`, auto); `upto` folds every turn
     * before `$turnId` ("Summarise up to here"); `from` folds `$turnId` and everything after it
     * ("Summarise from here"). Returns the new summary, or null when there was nothing to fold.
     *
     * @return array{text: string, scope: string, folded: list<string>, turns_folded: int, at: string}|null
     */
    public function compact(string $space, string $sessionId, string $subjectRef, ?string $focus = null, string $range = 'older', ?string $turnId = null, ?AiClient $as = null): ?array
    {
        $open = $this->sessions->open($space, $sessionId, $subjectRef);
        $session = $open['session'];
        $previous = TerminalSessions::summary($session);
        $already = array_flip(array_map('strval', (array) ($previous['folded'] ?? [])));
        $live = array_values(array_filter($open['turns'], fn (array $t) => ! TerminalSessions::rewound($t) && ! isset($already[(string) ($t['id'] ?? '')])));
        $pairs = SessionWindow::pairs($live);

        $chosen = match ($range) {
            'upto' => self::until($pairs, (string) $turnId),
            'from' => self::fromOn($pairs, (string) $turnId),
            default => array_slice($pairs, 0, max(0, count($pairs) - SessionWindow::RECENT_PAIRS)),
        };

        if ($chosen === []) {
            return null;
        }

        $result = ($as ?? $this->ai)->runTask(self::TASK, [
            'previous_summary' => $previous['text'] ?? '',
            // As JSON text: a template substitutes strings, and the model reads the turns as data.
            'turns' => (string) json_encode(array_map(fn (array $p) => [
                'ask' => $p['ask'],
                'reply' => $p['reply'],
                'steps' => $p['steps'],
                'outcome' => $p['outcome'],
            ], $chosen), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'focus' => trim((string) $focus),
        ])->array();

        $text = trim((string) ($result['summary'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('The summary came back empty, so the session was left as it was.');
        }

        $folded = array_values(array_unique(array_merge(
            array_keys($already),
            ...array_map(fn (array $p) => array_values(array_filter($p['ids'])), $chosen),
        )));

        $summary = [
            'text' => $text,
            'scope' => $range === 'from' ? 'after' : 'before',
            'folded' => array_slice($folded, -self::FOLDED_MAX),
            'turns_folded' => count($folded),
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $state = is_array($session['state'] ?? null) ? $session['state'] : [];
        $this->ai->spaces()->update($space, $sessionId, ['state' => array_merge($state, ['summary' => $summary])]);

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private static function until(array $pairs, string $turnId): array
    {
        foreach ($pairs as $i => $pair) {
            if (in_array($turnId, $pair['ids'], true)) {
                return array_slice($pairs, 0, $i);
            }
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private static function fromOn(array $pairs, string $turnId): array
    {
        foreach ($pairs as $i => $pair) {
            if (in_array($turnId, $pair['ids'], true)) {
                return array_slice($pairs, $i);
            }
        }

        return [];
    }
}
