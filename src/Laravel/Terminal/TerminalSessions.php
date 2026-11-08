<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;

/**
 * A console's work sessions (1.58.0, Porter console): one ai-service conversation per session on the
 * `terminal` channel, about ONE subject, in the person's space. The chat panel never lists them (the
 * service keeps every chat read on the `chat` channel).
 *
 * A session holds the person's asks and the assistant's answers, each answer with the steps it took
 * and what came of it, so the next ask can carry the session as context (`SessionWindow`) the way a
 * Claude Code session does.
 *
 * ⚠ THE BRIEF BELONGS TO THE SUBJECT, NOT THE PERSON. It is the subject's standing instructions (a
 * project's CLAUDE.md): everyone working on that page reads the same one, and it outlives `/clear`.
 * It is kept as ai-service memory owned by the subject ref, which no person's memory ever reads.
 */
final class TerminalSessions
{
    public const CHANNEL = 'terminal';

    /** Steps kept per answer: a Build that writes twenty sections is still one screen of lines. */
    public const STEPS_MAX = 60;

    public const STEP_TEXT_MAX = 200;

    /** A step's detail: one line, except the assistant's own thinking (a `think` step), a short paragraph. */
    public const STEP_DETAIL_MAX = 200;

    public const THINKING_MAX = 800;

    public const BRIEF_KEY = 'terminal.brief';

    public const BRIEF_MAX = 4000;

    /** Turns read when a session opens (the service's own page ceiling). */
    public const TURNS_MAX = 200;

    private const TITLE_MAX = 120;

    public function __construct(private readonly AiClient $ai) {}

    /** The subject's most recent session in this space, or null when it has none yet. */
    public function latest(string $space, string $subjectRef): ?array
    {
        return $this->list($space, $subjectRef, 1)[0] ?? null;
    }

    /**
     * This subject's sessions in this space, most recently active first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $space, string $subjectRef, int $limit = 20): array
    {
        $page = $this->ai->spaces()->conversations($space, [
            'channel' => self::CHANNEL,
            'subject_ref' => $subjectRef,
            'limit' => max(1, min(100, $limit)),
        ]);

        return array_values(array_filter(
            $page['conversations'] ?? [],
            // The service filters; this is the second line, so a stale service can never mix a chat thread in.
            fn (array $c) => ($c['channel'] ?? self::CHANNEL) === self::CHANNEL && ($c['subject_ref'] ?? null) === $subjectRef,
        ));
    }

    /** A new session on the subject (`/clear`, or the first ask). */
    public function start(string $space, string $subjectRef, ?string $title = null): array
    {
        return (array) ($this->ai->spaces()->start($space, [
            'channel' => self::CHANNEL,
            'subject_ref' => $subjectRef,
            'title' => $title === null ? null : self::clip($title, self::TITLE_MAX),
        ])['conversation'] ?? []);
    }

    /**
     * One session with its turns, oldest first. A session about ANOTHER subject is refused as missing,
     * so an id from one page can never open on another.
     *
     * @return array{session: array<string, mixed>, turns: list<array<string, mixed>>}
     */
    public function open(string $space, string $sessionId, string $subjectRef): array
    {
        $read = $this->ai->spaces()->conversation($space, $sessionId, ['limit' => self::TURNS_MAX]);
        $session = (array) ($read['conversation'] ?? []);

        if (($session['subject_ref'] ?? null) !== $subjectRef || ($session['channel'] ?? self::CHANNEL) !== self::CHANNEL) {
            throw new AiRequestRefusedException('This page has no session with that id.', 404, 'no_such_session');
        }

        return ['session' => $session, 'turns' => array_values((array) ($read['turns'] ?? []))];
    }

    /**
     * The person's ask, as it starts. Written before any work, so it is on record even if the tab closes.
     *
     * @param  array{target?: ?string, files?: list<string>, mode?: string, input?: string}  $meta
     */
    public function appendAsk(string $space, string $sessionId, string $ask, array $meta = []): array
    {
        $structured = array_filter([
            'target' => $meta['target'] ?? null,
            'files' => array_values(array_map(fn ($f) => self::clip((string) $f, 120), $meta['files'] ?? [])),
            'mode' => $meta['mode'] ?? 'build',
        ], fn ($v) => $v !== null && $v !== []);

        return (array) ($this->ai->spaces()->append($space, $sessionId, [
            'role' => 'user',
            'body' => $ask,
            'structured' => $structured,
            'surface' => 'studio',
            'input' => in_array($meta['input'] ?? 'typed', ['typed', 'dictated', 'hands_free', 'attachment', 'slash', 'mention'], true) ? ($meta['input'] ?? 'typed') : 'typed',
        ])['turn'] ?? []);
    }

    /**
     * The assistant's answer: its words, the steps it took, what came of it.
     *
     * @param  list<array{kind?: string, text?: string, ms?: ?int, detail?: ?string}>  $steps
     * @param  array<string, mixed>  $outcome  changed, elements, kept, conflicts, reason
     * @param  array{checkpoint_id?: ?string, run_id?: ?string, plan?: ?array}  $refs
     */
    public function appendAnswer(string $space, string $sessionId, ?string $reply, array $steps, array $outcome, array $refs = []): array
    {
        return (array) ($this->ai->spaces()->append($space, $sessionId, [
            'role' => 'assistant',
            'body' => $reply === null || trim($reply) === '' ? null : $reply,
            'structured' => array_filter([
                'steps' => self::steps($steps),
                'outcome' => $outcome,
                'checkpoint_id' => $refs['checkpoint_id'] ?? null,
                'run_id' => $refs['run_id'] ?? null,
                'plan' => $refs['plan'] ?? null,
            ], fn ($v) => $v !== null),
            'surface' => 'studio',
        ])['turn'] ?? []);
    }

    /**
     * Rewind the conversation to before a turn: that turn and every later one are marked rewound (kept,
     * shown dimmed, left out of the context). Returns how many turns were marked.
     */
    public function markRewound(string $space, string $sessionId, string $subjectRef, string $fromTurnId): int
    {
        $turns = $this->open($space, $sessionId, $subjectRef)['turns'];
        $from = array_search($fromTurnId, array_column($turns, 'id'), true);

        if ($from === false) {
            throw new AiRequestRefusedException('That ask is not in this session.', 404, 'no_such_turn');
        }

        $at = gmdate('Y-m-d\TH:i:s\Z');
        $marked = 0;

        foreach (array_slice($turns, (int) $from) as $turn) {
            if (self::rewound($turn)) {
                continue;
            }

            $this->ai->spaces()->turnState($space, $sessionId, (string) $turn['id'], array_merge((array) ($turn['state'] ?? []), ['rewound_at' => $at]));
            $marked++;
        }

        return $marked;
    }

    /** Whether a turn was rewound (it stays on screen, dimmed, and out of the context). */
    public static function rewound(array $turn): bool
    {
        return is_array($turn['state'] ?? null) && ($turn['state']['rewound_at'] ?? null) !== null;
    }

    /** The session's running summary (written by `SessionCompactor`), or null. */
    public static function summary(array $session): ?array
    {
        $summary = $session['state']['summary'] ?? null;

        return is_array($summary) && trim((string) ($summary['text'] ?? '')) !== '' ? $summary : null;
    }

    /** @return array{text: string, by: ?string, at: ?string}|null */
    public function brief(string $subjectRef): ?array
    {
        $value = $this->ai->memory()->all($subjectRef)[self::BRIEF_KEY]['value'] ?? null;

        return is_array($value) && trim((string) ($value['text'] ?? '')) !== ''
            ? ['text' => (string) $value['text'], 'by' => $value['by'] ?? null, 'at' => $value['at'] ?? null]
            : null;
    }

    /** Set (or with an empty text, remove) the subject's brief. `$by` is how the console names the writer. */
    public function setBrief(string $subjectRef, ?string $text, ?string $by): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            $this->ai->memory()->forget($subjectRef, self::BRIEF_KEY);

            return null;
        }

        $value = ['text' => self::clip($text, self::BRIEF_MAX), 'by' => $by, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->ai->memory()->put($subjectRef, self::BRIEF_KEY, $value);

        return $value;
    }

    /** @return list<array{kind: string, text: string, ms: ?int, detail: ?string}> */
    private static function steps(array $steps): array
    {
        $out = [];

        foreach (array_slice($steps, 0, self::STEPS_MAX) as $step) {
            $text = trim((string) ($step['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $out[] = [
                'kind' => (string) ($step['kind'] ?? 'step'),
                'text' => self::clip($text, self::STEP_TEXT_MAX),
                'ms' => isset($step['ms']) ? max(0, (int) $step['ms']) : null,
                'detail' => isset($step['detail']) && trim((string) $step['detail']) !== ''
                    ? self::clip((string) $step['detail'], ($step['kind'] ?? '') === 'think' ? self::THINKING_MAX : self::STEP_DETAIL_MAX)
                    : null,
            ];
        }

        return $out;
    }

    private static function clip(string $text, int $max): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($flat) > $max ? rtrim(mb_substr($flat, 0, $max - 1)).'…' : $flat;
    }
}
