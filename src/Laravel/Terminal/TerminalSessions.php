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

    /**
     * A step's detail and the assistant's own thinking (a `think` step), kept WHOLE up to a safety cap
     * (1.59.0, owner 2026-11-09: "instead of truncating the responses (especially reasoning), they should
     * be collapsed/folded"). The console folds them; a cap that is reached cuts at a word, never mid-word.
     */
    public const STEP_DETAIL_MAX = 2000;

    public const THINKING_MAX = 20000;

    /** What an answer may carry besides its words (1.59.0): the findings, questions and ideas. */
    public const FINDINGS_MAX = 8;

    public const QUESTIONS_MAX = 4;

    public const OPTIONS_MAX = 6;

    public const IDEAS_MAX = 6;

    /** The kinds of finding a summary may list, as Claude Code's summary does. */
    public const FINDING_KINDS = ['issue', 'error', 'gap', 'suggestion'];

    public const BRIEF_KEY = 'terminal.brief';

    public const BRIEF_MAX = 4000;

    /** The person's specialization choice for a subject (1.64): a guide's key, or `none` to turn guides off. */
    public const SPECIALIZATION_KEY = 'terminal.specialization';

    public const SPECIALIZATION_PATTERN = '/^(none|[a-z][a-z0-9-]{1,47})$/';

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
            // 1.62.1: the files' ids beside their names, in the same order, so a later ask can reach a file the
            // person attached earlier ("Compare with the picture"). Ids only, never the file.
            'file_ids' => array_values(array_map(fn ($f) => self::clip((string) $f, 64), array_slice((array) ($meta['file_ids'] ?? []), 0, 3))),
            'mode' => $meta['mode'] ?? 'build',
            // 1.59.0: the answers this ask gives to the assistant's questions, as the person picked them.
            'answers' => self::answers((array) ($meta['answers'] ?? [])),
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
     * 1.59.0 adds what the person may want besides the words: the level asked and the one that answered,
     * the effort, the findings (issues, errors, gaps, suggestions), the questions (each with choices), and
     * a brainstorm's ideas and recommendation.
     *
     * @param  array{checkpoint_id?: ?string, run_id?: ?string, plan?: ?array, level?: ?string, served_level?: ?string, effort?: ?string, findings?: list<array{kind: string, text: string}>, questions?: list<array<string, mixed>>, ideas?: list<array{title: string, why?: ?string}>, recommendation?: ?string}  $refs
     */
    public function appendAnswer(string $space, string $sessionId, ?string $reply, array $steps, array $outcome, array $refs = []): array
    {
        $level = fn ($v) => in_array($v, ['normal', 'pro', 'expert'], true) ? $v : null;

        return (array) ($this->ai->spaces()->append($space, $sessionId, [
            'role' => 'assistant',
            'body' => $reply === null || trim($reply) === '' ? null : $reply,
            'structured' => array_filter([
                'steps' => self::steps($steps),
                'outcome' => $outcome,
                'checkpoint_id' => $refs['checkpoint_id'] ?? null,
                'run_id' => $refs['run_id'] ?? null,
                'plan' => $refs['plan'] ?? null,
                'level' => $level($refs['level'] ?? null),
                'served_level' => $level($refs['served_level'] ?? null),
                'effort' => in_array($refs['effort'] ?? null, ['low', 'medium', 'high'], true) ? $refs['effort'] : null,
                'findings' => self::findings((array) ($refs['findings'] ?? [])),
                'questions' => self::questions((array) ($refs['questions'] ?? [])),
                'ideas' => self::ideas((array) ($refs['ideas'] ?? [])),
                'recommendation' => isset($refs['recommendation']) && trim((string) $refs['recommendation']) !== '' ? self::keep((string) $refs['recommendation'], self::STEP_DETAIL_MAX) : null,
            ], fn ($v) => $v !== null && $v !== []),
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

    /**
     * The person's own console commands (1.62.0, "saved asks"), kept with their space in ai-service.
     *
     * @return list<array{name: string, ask: string, description: ?string}>
     */
    public function commands(string $space): array
    {
        return $this->ai->spaces()->commands($space);
    }

    /**
     * @param  list<array{name: string, ask: string, description?: ?string}>  $commands
     * @return list<array{name: string, ask: string, description: ?string}>
     */
    public function saveCommands(string $space, array $commands): array
    {
        return $this->ai->spaces()->saveCommands($space, $commands);
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

    /** @return array{key: string, by: ?string, at: ?string}|null the person's choice, null when they left it to the page or the assistant */
    public function specializationChoice(string $subjectRef): ?array
    {
        $value = $this->ai->memory()->all($subjectRef)[self::SPECIALIZATION_KEY]['value'] ?? null;

        return is_array($value) && is_string($value['key'] ?? null) && preg_match(self::SPECIALIZATION_PATTERN, $value['key']) === 1
            ? ['key' => $value['key'], 'by' => $value['by'] ?? null, 'at' => $value['at'] ?? null]
            : null;
    }

    /** Set the person's choice (a key, or `none`); null removes it, leaving it to the page or the assistant again. */
    public function setSpecialization(string $subjectRef, ?string $key, ?string $by): ?array
    {
        if ($key === null || $key === '') {
            $this->ai->memory()->forget($subjectRef, self::SPECIALIZATION_KEY);

            return null;
        }

        $value = ['key' => $key, 'by' => $by, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->ai->memory()->put($subjectRef, self::SPECIALIZATION_KEY, $value);

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
                // Line breaks kept: the console folds a long detail by its lines.
                'detail' => isset($step['detail']) && trim((string) $step['detail']) !== ''
                    ? self::keep((string) $step['detail'], ($step['kind'] ?? '') === 'think' ? self::THINKING_MAX : self::STEP_DETAIL_MAX)
                    : null,
            ];
        }

        return $out;
    }

    /** @return list<array{kind: string, text: string}> */
    public static function findings(array $findings): array
    {
        $out = [];

        foreach ($findings as $f) {
            $kind = strtolower(trim((string) (is_array($f) ? ($f['kind'] ?? '') : '')));
            $text = trim((string) (is_array($f) ? ($f['text'] ?? '') : ''));

            if (in_array($kind, self::FINDING_KINDS, true) && $text !== '') {
                $out[] = ['kind' => $kind, 'text' => self::keep($text, 600)];
            }
        }

        return array_slice($out, 0, self::FINDINGS_MAX);
    }

    /**
     * A question with its choices, the console's multiple-choice tool: `{text, header, options: [{label,
     * description, recommended}], multi}`. A question with no choices is still a question (the person types).
     * 1.59.1: `header` (its tab's name when there are several) and `recommended` (the assistant's pick).
     *
     * @return list<array{text: string, options: list<array{label: string, description: ?string}>, multi: bool}>
     */
    public static function questions(array $questions): array
    {
        $out = [];

        foreach ($questions as $q) {
            $q = is_string($q) ? ['text' => $q] : (is_array($q) ? $q : []);
            $text = trim((string) ($q['text'] ?? $q['question'] ?? ''));

            if ($text === '') {
                continue;
            }

            $options = [];
            foreach (array_slice((array) ($q['options'] ?? []), 0, self::OPTIONS_MAX) as $o) {
                $label = trim((string) (is_array($o) ? ($o['label'] ?? '') : $o));
                if ($label !== '') {
                    $why = is_array($o) ? trim((string) ($o['description'] ?? '')) : '';
                    $options[] = ['label' => self::clip($label, 80), 'description' => $why === '' ? null : self::clip($why, 240)]
                        // The choice the assistant recommends (1.59.1): kept only when it says so.
                        + (is_array($o) && ($o['recommended'] ?? false) === true ? ['recommended' => true] : []);
                }
            }

            $header = trim((string) ($q['header'] ?? ''));
            $out[] = ['text' => self::keep($text, 600), 'options' => $options, 'multi' => (bool) ($q['multi'] ?? false)]
                // A word or two naming the question: its tab when there are several (1.59.1).
                + ($header !== '' ? ['header' => self::clip($header, 24)] : []);
        }

        return array_slice($out, 0, self::QUESTIONS_MAX);
    }

    /** @return list<array{title: string, why: ?string}> a brainstorm's ideas */
    public static function ideas(array $ideas): array
    {
        $out = [];

        foreach ($ideas as $i) {
            $title = trim((string) (is_array($i) ? ($i['title'] ?? '') : $i));
            if ($title !== '') {
                $why = is_array($i) ? trim((string) ($i['why'] ?? '')) : '';
                $out[] = ['title' => self::clip($title, 160), 'why' => $why === '' ? null : self::keep($why, 600)];
            }
        }

        return array_slice($out, 0, self::IDEAS_MAX);
    }

    /** @return list<array{question: string, answer: string}> the person's answers, as they read them */
    private static function answers(array $answers): array
    {
        $out = [];

        foreach (array_slice($answers, 0, self::QUESTIONS_MAX) as $a) {
            $question = trim((string) (is_array($a) ? ($a['question'] ?? '') : ''));
            $answer = trim((string) (is_array($a) ? ($a['answer'] ?? '') : ''));
            if ($question !== '' && $answer !== '') {
                $out[] = ['question' => self::clip($question, 600), 'answer' => self::keep($answer, 1000)];
            }
        }

        return $out;
    }

    /**
     * A text kept as written (line breaks and all), up to a cap that cuts at a word with "…". For what
     * the console FOLDS rather than cuts: thinking, details, findings.
     */
    public static function keep(string $text, int $max): string
    {
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text)));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max * 0.8 ? mb_substr($cut, 0, $space) : $cut).'…';
    }

    private static function clip(string $text, int $max): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($flat) > $max ? rtrim(mb_substr($flat, 0, $max - 1)).'…' : $flat;
    }
}
