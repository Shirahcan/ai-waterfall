<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * What one ask in a console carries of its session (1.58.0): the context hierarchy, nearest first.
 *
 *   1. BRIEF    the subject's standing instructions (its CLAUDE.md), shared by everyone working on it.
 *   2. SUMMARY  what compaction folded (`SessionCompactor`), one paragraph.
 *   3. NEARBY   no longer filled (1.83.0); kept in the shape so a reader of the window does not break.
 *   4. RECENT   EVERY live pair since the summary, VERBATIM: the ask, the reply, the steps taken, the
 *               outcome (including what failed and what the person kept), so "do that again" and "that
 *               did not work" resolve the way they do in a Claude Code session.
 *
 * ⚠ NOTHING IS DROPPED WITHOUT BEING SUMMARISED (1.83.0, Portify owner 2026-11-28). Until 1.82 only the last 3
 * pairs were verbatim, the 10 before them one line each, and anything older vanished with no summary: the
 * session forgot its own early asks, and the Context count FELL as it went on. Now, as in Claude Code, the whole
 * session since the last summary is carried and the count only grows; at COMPACT_AT of the budget the older
 * pairs are folded into the summary (`SessionCompactor`, keeping the last RECENT_PAIRS), once.
 * The per-message cap matches ai-service's thread mode (ChatHistory::THREAD_MESSAGE_MAX_CHARS), so a chat console
 * (which reads the thread there) and a design console count the same conversation the same way.
 *
 * Mirrors Portify's chat `ConversationWindow`, generalised. Pure: it reads turns already fetched and
 * never calls the service, so a product can build it inside a worker as well as a request.
 *
 * ⚠ PAIRS, NOT MESSAGES. An answer without its ask reads as something the assistant volunteered.
 * ⚠ REWOUND AND FOLDED TURNS ARE LEFT OUT. A rewound turn is shown dimmed on screen and never reaches
 * the model again; a folded one is in the summary instead.
 * ⚠ MIDDLE-ELISION, never a tail cut: an ask usually ends with what is actually wanted.
 */
final class SessionWindow
{
    public const RECENT_PAIRS = 3;

    public const NEARBY_PAIRS = 10;

    public const VERBATIM_MAX_CHARS = 12000;

    public const NEARBY_MAX_CHARS = 220;

    /** The share of the budget at which the next ask is summarised first. */
    public const COMPACT_AT = 0.8;

    /**
     * @param  array<string, mixed>  $session  the conversation summary (its `state` holds the summary)
     * @param  list<array<string, mixed>>  $turns  oldest first
     * @param  array{text: string}|null  $brief
     * @return array{brief: ?string, summary: ?array{text: string, scope: string}, nearby: list<string>, recent: list<array{ask: string, reply: ?string, steps: list<string>, outcome: string}>}
     */
    public static function build(array $session, array $turns, ?array $brief): array
    {
        $summary = TerminalSessions::summary($session);
        $folded = array_flip(array_map('strval', (array) ($summary['folded'] ?? [])));
        $live = array_values(array_filter($turns, fn (array $t) => ! TerminalSessions::rewound($t) && ! isset($folded[(string) ($t['id'] ?? '')])));
        $pairs = self::pairs($live);

        // Every live pair, word for word (1.83.0): what is older is in the summary, never silently gone.
        $recent = $pairs;
        $nearby = [];

        return [
            'brief' => $brief === null ? null : self::elide((string) $brief['text'], TerminalSessions::BRIEF_MAX),
            'summary' => $summary === null ? null : ['text' => (string) $summary['text'], 'scope' => (string) ($summary['scope'] ?? 'before')],
            'nearby' => array_map(fn (array $p) => self::line($p), $nearby),
            'recent' => array_map(fn (array $p) => [
                'ask' => self::elide($p['ask'], self::VERBATIM_MAX_CHARS),
                'reply' => $p['reply'] === null ? null : self::elide($p['reply'], self::VERBATIM_MAX_CHARS),
                'steps' => $p['steps'],
                'outcome' => $p['outcome'],
            ] + (($p['findings'] ?? []) !== [] ? ['findings' => $p['findings']] : []), $recent),
        ];
    }

    /**
     * How full the context is, part by part, in estimated tokens (characters / 4: an estimate, said so).
     *
     * @param  array<string, mixed>  $window  from build()
     * @return array{parts: array{brief: int, summary: int, turns: int, design: int, files: int}, used: int, budget: int, percent: int, compact_at: int}
     */
    public static function size(array $window, int $designChars, int $budgetTokens, int $filesChars = 0): array
    {
        $tokens = fn (int $chars) => (int) ceil($chars / 4);
        $turns = mb_strlen(implode("\n", $window['nearby'] ?? []))
            + mb_strlen((string) json_encode($window['recent'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $parts = [
            'brief' => $tokens(mb_strlen((string) ($window['brief'] ?? ''))),
            'summary' => $tokens(mb_strlen((string) ($window['summary']['text'] ?? ''))),
            'turns' => $tokens($turns),
            'design' => $tokens(max(0, $designChars)),
            'files' => $tokens(max(0, $filesChars)),
        ];
        $used = array_sum($parts);
        $budget = max(1, $budgetTokens);

        return [
            'parts' => $parts,
            'used' => $used,
            'budget' => $budget,
            'percent' => (int) min(999, round($used * 100 / $budget)),
            'compact_at' => (int) round(self::COMPACT_AT * 100),
        ];
    }

    /** Whether the next ask should be summarised first: over the line AND something left to fold. */
    public static function needsCompaction(array $window, array $size): bool
    {
        $foldable = count($window['recent'] ?? []) > self::RECENT_PAIRS || ($window['nearby'] ?? []) !== [];

        return ($size['used'] ?? 0) >= self::COMPACT_AT * max(1, (int) ($size['budget'] ?? 1)) && $foldable;
    }

    /**
     * Ask/answer pairs. An ask with no answer yet (the one running now, or one that was stopped) still
     * counts: "Could not finish" is context too.
     *
     * @return list<array{ask: string, reply: ?string, steps: list<string>, outcome: string, ids: list<string>}>
     */
    public static function pairs(array $turns): array
    {
        $pairs = [];
        $open = null;

        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                if ($open !== null) {
                    $pairs[] = $open;
                }
                $open = ['ask' => trim((string) ($turn['body'] ?? '')), 'reply' => null, 'steps' => [], 'outcome' => 'No answer was recorded (stopped or interrupted).', 'ids' => [(string) ($turn['id'] ?? '')]];

                continue;
            }

            if (($turn['role'] ?? '') !== 'assistant' || $open === null) {
                continue;
            }

            $s = is_array($turn['structured'] ?? null) ? $turn['structured'] : [];
            $open['reply'] = trim((string) ($turn['body'] ?? '')) === '' ? null : trim((string) $turn['body']);
            // ⚠ THE ASSISTANT'S OWN THINKING STAYS OUT (1.59.0): it is kept whole for the person to unfold
            // (up to 20,000 characters), and handing it back would spend the window on reasoning already
            // acted on. A step's detail is one short line here.
            $open['steps'] = array_values(array_map(
                fn (array $step) => trim((string) ($step['text'] ?? '')).(($step['detail'] ?? null) ? ' ('.self::elide(trim((string) $step['detail']), 200).')' : ''),
                array_filter((array) ($s['steps'] ?? []), fn ($step) => is_array($step) && ($step['kind'] ?? '') !== 'think'),
            ));
            // What the assistant pointed out (issues, gaps, suggestions): the next ask may be about them.
            $open['findings'] = array_values(array_map(
                fn (array $f) => ucfirst((string) ($f['kind'] ?? 'note')).': '.trim((string) ($f['text'] ?? '')),
                array_filter((array) ($s['findings'] ?? []), 'is_array'),
            ));
            $open['outcome'] = self::outcome((array) ($s['outcome'] ?? []), is_array($s['plan'] ?? null) ? $s['plan'] : null);
            // A proposed change and what became of it (1.82.0): the next ask carries on from what it made, or from where it stopped.
            $open['outcome'] .= self::decided((array) ($s['outcome'] ?? []), is_array($turn['card_state'] ?? null) ? $turn['card_state'] : null);
            $open['ids'][] = (string) ($turn['id'] ?? '');
            $pairs[] = $open;
            $open = null;
        }

        if ($open !== null) {
            $pairs[] = $open;
        }

        return $pairs;
    }

    /**
     * What came of an answer, in one sentence the model and the person both read.
     *
     * ⚠ SAVED DETAILS ARE A CHANGE TOO (1.75.1, Portify owner 2026-11-23). An answer that saved the subject's details
     * (a post's summary) carries them on `outcome.details` with `changed` false (the design did not change). Read as
     * "Nothing changed.", the recap told the person their summary was never set, and every later ask's window told the
     * model the same; so the details are named here, apart from the design.
     */
    /**
     * The proposed change on an answer and what the person did with it (1.82.0). Read by the model, so it names no
     * speaker: what was proposed, then that it ran (with the run's own summary), was declined, or still waits.
     */
    public static function decided(array $outcome, ?array $cardState): string
    {
        $op = $outcome['operation'] ?? null;
        if (! is_array($op)) {
            return '';
        }
        $title = trim((string) ($op['preview']['title'] ?? $op['operation'] ?? 'a change'));
        $summary = trim((string) ($cardState['summary'] ?? ''));

        return ' Proposed for approval: "'.$title.'". '.match ((string) ($cardState['card'] ?? '')) {
            'done' => ($cardState['partial'] ?? false) === true
                ? 'The person approved it and it ran only part of the way'.($summary !== '' ? ': '.$summary : '.')
                : 'The person approved it and it ran'.($summary !== '' ? ': '.$summary : '.'),
            'started' => 'The person approved it; it is running in the background.',
            'cancelled' => 'The person declined it; nothing ran.',
            default => 'Not approved yet; nothing has run.',
        };
    }

    public static function outcome(array $outcome, ?array $plan = null): string
    {
        if ($plan !== null) {
            return 'Proposed a plan, nothing changed yet: '.implode('; ', array_map('strval', (array) ($plan['steps'] ?? [])));
        }

        $details = self::details($outcome);
        $proposed = is_array($outcome['proposal'] ?? null) ? ' More details were proposed for the person to apply; not saved yet.' : '';

        if (($outcome['changed'] ?? false) === true) {
            $n = (int) ($outcome['elements'] ?? 0);
            $line = $n > 0 ? 'Changed '.$n.' '.($n === 1 ? 'element' : 'elements').'.' : 'Changed the design.';
            $kept = (int) ($outcome['kept'] ?? 0);
            $conflicts = (int) ($outcome['conflicts'] ?? 0);

            if ($conflicts > 0) {
                $line .= ' The person had changed '.$conflicts.' of the same '.($conflicts === 1 ? 'element' : 'elements').' meanwhile; their version was kept.';
            } elseif ($kept > 0) {
                $line .= ' Edits the person made meanwhile were kept.';
            }

            return $line.($details !== [] ? ' Also saved these details: '.implode(', ', $details).'.' : '').$proposed;
        }

        if ($details !== []) {
            return 'Saved these details: '.implode(', ', $details).' (the design did not change).'.$proposed;
        }

        $reason = trim((string) ($outcome['reason'] ?? ''));

        return $reason !== '' ? 'Could not finish: '.$reason : 'Nothing changed.'.$proposed;
    }

    /** @return list<string> the details an answer saved, by their names on the screen */
    private static function details(array $outcome): array
    {
        $names = array_map(fn ($d) => is_string($d) ? trim($d) : '', (array) ($outcome['details'] ?? []));

        return array_values(array_unique(array_filter($names, fn (string $d) => $d !== '')));
    }

    private static function line(array $pair): string
    {
        return self::elide($pair['ask'], self::NEARBY_MAX_CHARS - 60).' -> '.self::elide($pair['outcome'], 60);
    }

    private static function elide(string $text, int $max): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $head = (int) floor(($max - 3) * 0.6);
        $tail = max(0, $max - 3 - $head);

        return rtrim(mb_substr($text, 0, $head)).' … '.ltrim(mb_substr($text, -$tail));
    }
}
