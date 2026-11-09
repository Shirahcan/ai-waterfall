<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Shirahcan\AiClient\AiClient;

/**
 * The recap of a console session for a person coming back to it (1.60.0, owner 2026-11-09: "Recap should
 * use AI"): a few lines, written by the assistant in the first person, on what is being made, what changed
 * or failed, what is waiting on the person and a sensible next step.
 *
 * ⚠ EVERY CALL HAS A READER. The panel asks for it when the person returns to the console after their set
 * time away, opens a session whose last turn is older than that, or asks (`/recap`). Never on a timer.
 *
 * ⚠ PAID ONCE PER POINT IN THE SESSION. The recap is kept on the session with the last turn it describes;
 * coming back again with nothing new reads it back free. A new ask or a rewind moves the last turn on.
 */
final class SessionRecap
{
    /** ai-service's SHARED prompt (`resources/ai-tasks/terminal`), published on every ai-service deploy. */
    public const TASK = 'shared.terminal.recap';

    /** The most recent asks the recap reads; older work reaches it through the session's summary. */
    private const RECENT_PAIRS = 6;

    private const LINES_MAX = 4;

    private const LINE_MAX = 400;

    public function __construct(private readonly AiClient $ai, private readonly TerminalSessions $sessions) {}

    /**
     * The recap of one session, or null when nothing has been asked in it yet.
     *
     * @return array{lines: list<string>, for_turn: string, at: string, cached: bool}|null
     *
     * @throws \RuntimeException when the recap came back empty
     */
    public function recap(string $space, string $sessionId, string $subjectRef, string $subjectLabel, ?int $awayMinutes = null): ?array
    {
        $open = $this->sessions->open($space, $sessionId, $subjectRef);
        $session = $open['session'];
        $live = array_values(array_filter($open['turns'], fn (array $t) => ! TerminalSessions::rewound($t)));

        if (array_filter($live, fn (array $t) => ($t['role'] ?? '') === 'user') === []) {
            return null;
        }

        $lastTurn = $live[count($live) - 1];
        $last = (string) ($lastTurn['id'] ?? '');
        $state = is_array($session['state'] ?? null) ? $session['state'] : [];
        $kept = is_array($state['recap'] ?? null) ? $state['recap'] : null;

        if ($kept !== null && ($kept['for_turn'] ?? null) === $last && self::clean($kept['lines'] ?? []) !== []) {
            return ['lines' => self::clean($kept['lines']), 'for_turn' => $last, 'at' => (string) ($kept['at'] ?? ''), 'cached' => true];
        }

        $pairs = array_slice(SessionWindow::pairs($live), -self::RECENT_PAIRS);
        $summary = TerminalSessions::summary($session);

        $result = $this->ai->runTask(self::TASK, [
            'assistant' => trim((string) config('ai-client.assistant.display_name')) ?: 'the assistant',
            'subject' => $subjectLabel,
            'away' => self::away($awayMinutes),
            'summary' => (string) ($summary['text'] ?? ''),
            // As JSON text: a template substitutes strings, and the model reads the turns as data.
            'turns' => (string) json_encode(array_map(fn (array $p) => [
                'ask' => $p['ask'],
                'reply' => $p['reply'],
                'steps' => $p['steps'],
                'pointed_out' => $p['findings'] ?? [],
                'outcome' => $p['outcome'],
            ], $pairs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'waiting' => self::waiting($lastTurn),
        ])->array();

        $lines = self::clean($result['lines'] ?? []);

        if ($lines === []) {
            throw new \RuntimeException('The recap came back empty.');
        }

        $recap = ['for_turn' => $last, 'lines' => $lines, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->ai->spaces()->update($space, $sessionId, ['state' => array_merge($state, ['recap' => $recap])]);

        return $recap + ['cached' => false];
    }

    /**
     * The model's lines, kept to what the panel draws: non-empty strings, each cut at a word.
     *
     * @return list<string>
     */
    private static function clean(mixed $lines): array
    {
        $out = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            $text = trim(preg_replace('/\s+/u', ' ', is_string($line) ? $line : '') ?? '');

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > self::LINE_MAX) {
                $cut = mb_substr($text, 0, self::LINE_MAX);
                $space = mb_strrpos($cut, ' ');
                $text = rtrim($space !== false && $space > self::LINE_MAX / 2 ? mb_substr($cut, 0, $space) : $cut).'…';
            }

            $out[] = $text;

            if (count($out) === self::LINES_MAX) {
                break;
            }
        }

        return $out;
    }

    /** How long the person was away, in words the model can repeat. */
    private static function away(?int $minutes): string
    {
        if ($minutes === null || $minutes < 1) {
            return 'they have just opened the session';
        }
        if ($minutes < 90) {
            return "about {$minutes} minutes";
        }
        if ($minutes < 36 * 60) {
            return 'about '.(int) round($minutes / 60).' hours';
        }

        return 'about '.(int) round($minutes / 1440).' days';
    }

    /** What the session's last answer leaves for the person to do, in one line. */
    private static function waiting(array $turn): string
    {
        if (($turn['role'] ?? '') !== 'assistant') {
            return '';
        }

        $s = is_array($turn['structured'] ?? null) ? $turn['structured'] : [];

        if (is_array($s['plan'] ?? null)) {
            return 'A plan to build or dismiss: '.trim((string) ($s['plan']['summary'] ?? ''));
        }

        $questions = array_values(array_filter((array) ($s['questions'] ?? []), 'is_array'));
        if ($questions !== []) {
            return 'Questions to answer: '.implode(' / ', array_map(fn (array $q) => trim((string) ($q['text'] ?? '')), $questions));
        }

        if (is_string($s['recommendation'] ?? null) && trim($s['recommendation']) !== '') {
            return 'A recommendation to build: '.trim($s['recommendation']);
        }

        return '';
    }
}
