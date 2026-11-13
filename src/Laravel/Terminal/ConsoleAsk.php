<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Laravel\Spaces\Spaces;
use Throwable;

/**
 * One ask made in a console (1.67.0, moved from Portify's ConsoleAsk for the shared console, C1b): the ONE place
 * that checks the session belongs to this subject, records the ask, takes the rewind point, and later records
 * the answer with its steps and outcome.
 *
 * A product extends it with what only it knows: its context budget, what a run's result MEANS (did it change
 * the subject, which parts, what the assistant noticed), and which failure is a Stop.
 *
 * ⚠ THE ASK IS RECORDED BEFORE ANY WORK, and the answer by the WORKER, never the browser: a tab closed mid-run
 * still leaves the session whole.
 * ⚠ THE CONVERSATION IS NEVER WHAT DECIDES THE SUBJECT. If ai-service cannot be reached the run goes ahead
 * without the session's context, and the console says the history is unavailable.
 */
abstract class ConsoleAsk
{
    /** The console block a run carries in its payload. */
    public const PAYLOAD_KEY = 'console';

    /*
     * Why a plan or a brainstorm did not finish, as the session records it. The person reads it in the
     * transcript and the MODEL reads it in the next ask's session, so it names no speaker.
     */
    public const NO_PLAN = 'The plan did not come back just now. Nothing has changed.';

    public const NO_BRAINSTORM = 'The brainstorm did not come back just now. Nothing has changed.';

    public const NO_COMPARE = 'The comparison did not come back just now. Nothing has changed.';

    /** The subject as drawn, captured in the browser for "Compare with the picture" (the frontend names it so). */
    public const RENDER_NAME = 'porter-render';

    /** Asks that change nothing, so they take no rewind point. */
    public const THINKING_MODES = ['plan', 'brainstorm', 'compare'];

    public function __construct(
        protected readonly TerminalSessions $sessions,
        protected readonly CheckpointStore $checkpoints,
        protected readonly SessionCompactor $compactor,
    ) {}

    /** How many tokens the session window may fill beside the subject (the product's console budget). */
    /**
     * The subject this ask's console serves (1.72.0). The bound `TerminalSubject` by default; a product with a second
     * console surface (Portify's email templates) overrides it in that surface's subclass.
     */
    protected function subject(): TerminalSubject
    {
        return app(TerminalSubject::class);
    }

    abstract protected function budget(): int;

    /**
     * What a run's result means, in the product's terms.
     *
     * @param  array<string, mixed>  $console  from begin()
     * @param  array<string, mixed>  $result  what the work returned ({summary?, data?})
     * @return array{ok: bool, changed: bool, reply: string, findings: list<array<string, mixed>>, changes?: array{changes: list<array<string, mixed>>, total: int}|null, elements?: ?int, reason?: ?string}
     */
    abstract protected function outcomeOf(array $console, array $result): array;

    /** Whether a failure is the person's Stop (recorded as a stop, never offered again one level up). */
    protected function isStop(Throwable $e): bool
    {
        return false;
    }

    /**
     * Start a console ask, or null when the request names no session.
     *
     * The console sends, beside the ask, `session_id`, and for the transcript `console_target` (the part the
     * ask is aimed at, as the person names it) and `console_files` (the names of its files).
     *
     * @param  array<int|string, mixed>  $design  the subject as it is before this ask (the rewind point)
     * @return array{space: string, session_id: string, subject_ref: string, ask_turn_id: ?string, checkpoint_id: ?string, level: ?string, effort: ?string, timezone: ?string}|array{unavailable: true}|null
     *
     * @throws ValidationException when the session is not this subject's
     */
    public function begin(Request $request, string $kind, string $id, array $design, string $instruction, string $mode = 'build'): ?array
    {
        $sessionId = $request->input('session_id');

        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $target = $request->input('console_target');
        $files = $request->input('console_files');
        $answers = $request->input('answers');
        $ids = $request->input('reference_ids');
        $meta = [
            'target' => is_string($target) && trim($target) !== '' ? mb_substr(trim($target), 0, 80) : null,
            'files' => is_array($files) ? array_values(array_map(fn ($f) => mb_substr((string) $f, 0, 120), array_slice(array_filter($files, 'is_string'), 0, 3))) : [],
            // Beside the names, in the same order: "Compare with the picture" finds the person's last picture
            // from here, after a reload too. Ids only; the file stays the person's own.
            'file_ids' => is_array($ids) ? array_values(array_map(fn ($f) => mb_substr((string) $f, 0, 64), array_slice(array_filter($ids, 'is_string'), 0, 3))) : [],
            'mode' => $mode,
            // The answers this ask gives to the assistant's questions (drawn under the ask).
            'answers' => is_array($answers) ? array_values(array_filter($answers, 'is_array')) : [],
        ];

        $ref = $this->subject()->ref($request, $kind, $id);
        $space = $ref === null ? null : app(Spaces::class)->current($request);

        if ($ref === null || $space === null) {
            throw ValidationException::withMessages(['session_id' => 'This has no console.']);
        }

        try {
            $this->sessions->open($space, $sessionId, $ref);
            $ask = $this->sessions->appendAsk($space, $sessionId, $instruction, $meta);
        } catch (AiRequestRefusedException) {
            throw ValidationException::withMessages(['session_id' => 'That console session is not this one\'s. Open the console again and ask there.']);
        } catch (AiUnavailableException $e) {
            report($e);

            return ['unavailable' => true];
        }

        $userId = $request->user()?->getAuthIdentifier();
        $point = in_array($mode, self::THINKING_MODES, true)
            ? null
            : $this->checkpoints->rewindPoint($kind, $id, $design, $sessionId, $userId === null ? null : (string) $userId);

        $level = $request->input('level');
        $effort = $request->input('effort');

        return [
            'space' => $space,
            'session_id' => $sessionId,
            'subject_ref' => $ref,
            'ask_turn_id' => isset($ask['id']) ? (string) $ask['id'] : null,
            'checkpoint_id' => $point,
            // How hard the assistant works on this ask: every AI call of it, RunOptions.
            'level' => in_array($level, RunOptions::LEVELS, true) ? $level : null,
            'effort' => in_array($effort, RunOptions::EFFORTS, true) ? $effort : null,
            // The browser's zone, for a question the chat engine answers: "this week" is theirs.
            'timezone' => self::zone($request->input('client_timezone')),
        ];
    }

    /** A real IANA zone the browser sent, or null. */
    private static function zone(mixed $zone): ?string
    {
        return is_string($zone) && $zone !== '' && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : null;
    }

    /**
     * The session as context for this ask (the brief, the summary, the earlier turns), summarising it first
     * when it no longer fits. The ask itself is left out: it is the instruction.
     *
     * @param  array<string, mixed>  $console  from begin()
     * @return array{window: array<string, mixed>|null, compacted: bool}
     */
    public function window(array $console, int $designChars): array
    {
        if (! isset($console['session_id'])) {
            return ['window' => null, 'compacted' => false];
        }

        try {
            $read = fn () => $this->sessions->open($console['space'], $console['session_id'], $console['subject_ref']);
            $build = function (array $open) use ($console): array {
                $turns = array_values(array_filter($open['turns'], fn (array $t) => (string) ($t['id'] ?? '') !== (string) ($console['ask_turn_id'] ?? '')));

                return SessionWindow::build($open['session'], $turns, $this->sessions->brief($console['subject_ref']));
            };

            $window = $build($read());
            $size = SessionWindow::size($window, $designChars, $this->budget());

            if (! SessionWindow::needsCompaction($window, $size)) {
                return ['window' => $window, 'compacted' => false];
            }

            $this->compactor->compact($console['space'], $console['session_id'], $console['subject_ref']);

            return ['window' => $build($read()), 'compacted' => true];
        } catch (Throwable $e) {
            report($e);

            return ['window' => null, 'compacted' => false];
        }
    }

    /**
     * Run a console Build with thinking on, then record its answer, failure included.
     *
     * @param  array<string, mixed>  $console  from begin()
     * @param  callable(): array{summary?: string, data?: array<string, mixed>}  $work
     * @return array<string, mixed>  what the Build returned
     */
    public function around(array $console, RunLog $log, string $runId, callable $work): array
    {
        $served = null;

        try {
            // The level and effort the person chose reach every call of the run; the level that answered is
            // read inside the scope, before it closes.
            $result = $this->tuned($console, function () use ($log, $work, &$served) {
                try {
                    return StreamThinking::during(fn (string $soFar) => $log->thinking($soFar), $work);
                } finally {
                    $served = RunOptions::servedLevel();
                }
            });
        } catch (Throwable $e) {
            $stopped = $this->isStop($e) ? ['stopped' => true] : [];
            $this->finish($console, null, $log->finish(), ['changed' => false, 'reason' => $e->getMessage(), ...$stopped], $runId, null, ['served_level' => $served]);

            throw $e;
        }

        $data = (array) ($result['data'] ?? []);
        $out = $this->outcomeOf($console, $result);
        $reply = $out['reply'];
        $findings = $out['findings'];
        $changes = $out['changes'] ?? null;

        // On the run's record too, so the assistant can say how hard a Build ran.
        if (($console['level'] ?? null) !== null || ($console['effort'] ?? null) !== null) {
            $result['data'] = [...$data, 'level' => $console['level'] ?? null, 'served_level' => $served, 'effort' => $console['effort'] ?? null];
        }

        if ($findings !== []) {
            $result['summary'] = $reply;
            if (isset($result['data']['reply'])) {
                $result['data']['reply'] = $reply;
            }
            $result['data']['findings'] = $findings;
        }

        $this->finish($console, $reply, $log->finish(), [
            'changed' => $out['changed'],
            'elements' => $out['elements'] ?? ($changes !== null && $changes['total'] > 0 ? $changes['total'] : null),
            ...($changes !== null && $changes['changes'] !== [] ? ['changes' => $changes['changes']] : []),
            // The subject's record's fields the assistant drafted, to Apply or Dismiss (the values stay on the run).
            ...(is_array($data['proposal'] ?? null) ? ['proposal' => ['title' => (string) $data['proposal']['title'], 'fields' => array_values((array) $data['proposal']['fields'])]] : []),
            'reason' => $out['ok'] ? null : (string) ($out['reason'] ?? ''),
        ], $runId, null, [
            'served_level' => $served,
            'findings' => $findings,
            'questions' => (array) ($data['questions'] ?? []),
        ]);

        return $result;
    }

    /**
     * The person's last pictures in this session, newest ask first ("Compare with the picture"): the ids an
     * earlier ask carried beside its file names, pictures only. Empty when there are none.
     *
     * @param  array<string, mixed>|null  $console  from begin()
     * @return list<string>
     */
    public function picturesOf(?array $console): array
    {
        if (! isset($console['session_id'])) {
            return [];
        }

        try {
            $turns = $this->sessions->open($console['space'], $console['session_id'], $console['subject_ref'])['turns'];
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        foreach (array_reverse($turns) as $turn) {
            if (($turn['role'] ?? '') !== 'user' || TerminalSessions::rewound($turn) || (string) ($turn['id'] ?? '') === (string) ($console['ask_turn_id'] ?? '')) {
                continue;
            }

            $names = array_values((array) ($turn['structured']['files'] ?? []));
            $ids = array_values((array) ($turn['structured']['file_ids'] ?? []));
            $pictures = [];

            foreach ($ids as $i => $id) {
                $name = (string) ($names[$i] ?? '');

                // The subject's own render, sent by an earlier Compare, is never "the person's picture".
                if (is_string($id) && preg_match('/\.(png|jpe?g|webp|gif)$/i', $name) === 1 && ! str_starts_with($name, self::RENDER_NAME)) {
                    $pictures[] = $id;
                }
            }

            if ($pictures !== []) {
                return $pictures;
            }
        }

        return [];
    }

    /**
     * Run `$work` at the console ask's level and effort. Without a console session the work runs exactly as it
     * did; in one, every call is counted under the session (/cost) and runs at its level and effort when chosen.
     *
     * @template T
     *
     * @param  array<string, mixed>|null  $console  from begin()
     * @param  callable(): T  $work
     * @param  ?string  $served  set to the level that answered (null when none was asked)
     * @return T
     */
    public function tuned(?array $console, callable $work, ?string &$served = null): mixed
    {
        if (! isset($console['session_id'])) {
            return $work();
        }

        return RunOptions::during($console['level'] ?? null, $console['effort'] ?? null, function () use ($work, &$served) {
            try {
                return $work();
            } finally {
                $served = RunOptions::servedLevel();
            }
        }, (string) $console['session_id']);
    }

    /**
     * Record a plan or a brainstorm (nothing changes, no rewind point): the answer, and for a brainstorm its
     * ideas, questions and recommendation.
     *
     * @param  array<string, mixed>|null  $console  from begin()
     * @param  array<string, mixed>  $result  the plan's or the brainstorm's (`reason`: why it did not finish)
     * @param  list<array<string, mixed>>  $steps  what it did, when it ran on a worker
     */
    public function finishThinking(?array $console, array $result, bool $brainstorm, ?string $served, array $steps = []): void
    {
        if ($console === null) {
            return;
        }

        $unavailable = (bool) ($result['unavailable'] ?? false);
        $why = is_string($result['reason'] ?? null) && $result['reason'] !== '' ? $result['reason'] : null;
        $outcome = ['changed' => false, 'reason' => $unavailable ? ($why ?? ($brainstorm ? self::NO_BRAINSTORM : self::NO_PLAN)) : null]
            + (($result['stopped'] ?? false) === true ? ['stopped' => true] : []);

        if ($brainstorm) {
            $this->finish($console, $result['reply'] ?? null, $steps, $outcome, null, null, [
                'served_level' => $served,
                // A compare's differences: suggestions, each with "Do this".
                'findings' => $unavailable ? [] : array_values((array) ($result['findings'] ?? [])),
                'ideas' => $unavailable ? [] : (array) ($result['ideas'] ?? []),
                'questions' => $unavailable ? [] : (array) ($result['questions'] ?? []),
                'recommendation' => $unavailable ? null : ($result['recommendation'] ?? null),
            ]);

            return;
        }

        $this->finish($console, $result['summary'] ?? null, $steps, $outcome, null,
            $unavailable ? null : ['summary' => (string) $result['summary'], 'steps' => array_values($result['steps'] ?? [])],
            ['served_level' => $served]);
    }

    /**
     * Record the answer: the assistant's words, the steps it took, what came of it, and the rewind point.
     *
     * @param  array<string, mixed>  $console  from begin()
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $outcome
     * @param  array<string, mixed>|null  $plan
     * @param  array{served_level?: ?string, findings?: list<array<string, mixed>>, questions?: list<array<string, mixed>>, ideas?: list<array<string, mixed>>, recommendation?: ?string}  $extra
     */
    public function finish(array $console, ?string $reply, array $steps, array $outcome, ?string $runId = null, ?array $plan = null, array $extra = []): void
    {
        if (! isset($console['session_id'])) {
            return;
        }

        try {
            $this->sessions->appendAnswer($console['space'], $console['session_id'], $reply, $steps, $outcome, [
                'checkpoint_id' => ($outcome['changed'] ?? false) === true ? ($console['checkpoint_id'] ?? null) : null,
                'run_id' => $runId,
                'plan' => $plan,
                // The level asked for and the one that answered, and the effort (named, never the model).
                'level' => $console['level'] ?? null,
                'served_level' => $extra['served_level'] ?? null,
                'effort' => $console['effort'] ?? null,
                'findings' => $extra['findings'] ?? [],
                'questions' => $extra['questions'] ?? [],
                'ideas' => $extra['ideas'] ?? [],
                'recommendation' => $extra['recommendation'] ?? null,
            ]);
        } catch (Throwable $e) {
            // The subject already changed (or not) on its own terms; a missing line in the history is reported.
            report($e);
        }
    }
}
