<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Laravel\Spaces\Spaces;
use Throwable;

/**
 * A console's routes (1.58.0): its sessions, brief, context size, compaction and rewind, all through
 * the person's space and about one subject the product has said they may work on.
 *
 * ⚠ THE SUBJECT IS RESOLVED FIRST, ON EVERY ROUTE. No binding, or a subject this person may not edit,
 * is a 404 before the service is called, so nothing about another subject is ever confirmed.
 */
final class TerminalController extends Controller
{
    private const HISTORY_SESSIONS = 3;

    private const HISTORY_MAX = 50;

    public function __construct(
        private readonly Container $app,
        private readonly Spaces $spaces,
        private readonly TerminalSessions $sessions,
        private readonly SessionCompactor $compactor,
        private readonly SessionRecap $recaps,
        private readonly SessionUsage $usage,
    ) {}

    /**
     * What this session has cost so far, by level (1.62.0, `/cost`): read from ai-service, which counts
     * every call the session's asks, recaps and summaries made. `usage: null` when it cannot be read.
     */
    public function usage(Request $request, string $kind, string $id, string $session): JsonResponse
    {
        return $this->on($request, $kind, $id, function (string $space, string $ref) use ($session) {
            // Opened first: another person's session, or another subject's, is a 404 before anything is read.
            $open = $this->sessions->open($space, $session, $ref);

            try {
                return ['usage' => $this->usage->of($session, isset($open['session']['created_at']) ? (string) $open['session']['created_at'] : null)];
            } catch (AiRequestRefusedException|AiUnavailableException $e) {
                report($e);

                return ['usage' => null];
            }
        });
    }

    /** The person's own console commands (1.62.0, "saved asks"), from their space. */
    public function commands(Request $request, string $kind, string $id): JsonResponse
    {
        return $this->on($request, $kind, $id, fn (string $space) => ['commands' => $this->sessions->commands($space)]);
    }

    /** Replace the person's own commands (the console edits them as one list). */
    public function saveCommands(Request $request, string $kind, string $id): JsonResponse
    {
        $data = $request->validate([
            'commands' => ['present', 'array', 'max:30'],
            'commands.*.name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,23}$/', 'distinct'],
            'commands.*.ask' => ['required', 'string', 'max:2000'],
            'commands.*.description' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->on($request, $kind, $id, fn (string $space) => ['commands' => $this->sessions->saveCommands($space, array_values($data['commands']))]);
    }

    /** The subject's console as it opens: the latest session (none yet: null), brief, size, history. */
    public function show(Request $request, string $kind, string $id): JsonResponse
    {
        return $this->on($request, $kind, $id, function (string $space, string $ref, TerminalSubject $subject) {
            $latest = $this->sessions->latest($space, $ref);

            return $this->console($space, $ref, $subject, $latest === null ? null : $this->sessions->open($space, (string) $latest['id'], $ref));
        });
    }

    /** `/clear`: a new session. The design is untouched and the old session stays for `/resume`. */
    public function start(Request $request, string $kind, string $id): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:300']]);

        return $this->on($request, $kind, $id, function (string $space, string $ref, TerminalSubject $subject) use ($data) {
            $session = $this->sessions->start($space, $ref, $data['title'] ?? null);

            return $this->console($space, $ref, $subject, ['session' => $session, 'turns' => []]);
        });
    }

    /** `/resume`: this subject's sessions, newest activity first. */
    public function index(Request $request, string $kind, string $id): JsonResponse
    {
        return $this->on($request, $kind, $id, fn (string $space, string $ref) => [
            'sessions' => array_map(fn (array $s) => self::sessionRow($s), $this->sessions->list($space, $ref, 30)),
        ]);
    }

    public function open(Request $request, string $kind, string $id, string $session): JsonResponse
    {
        return $this->on($request, $kind, $id, fn (string $space, string $ref, TerminalSubject $subject) => $this->console($space, $ref, $subject, $this->sessions->open($space, $session, $ref)));
    }

    /** `/compact [focus]`, "Summarise up to here" and "Summarise from here". */
    public function compact(Request $request, string $kind, string $id, string $session): JsonResponse
    {
        $data = $request->validate([
            'focus' => ['nullable', 'string', 'max:500'],
            'range' => ['nullable', 'in:older,upto,from'],
            'turn' => ['nullable', 'required_if:range,upto,from', 'uuid'],
        ]);

        return $this->on($request, $kind, $id, function (string $space, string $ref, TerminalSubject $subject) use ($data, $session) {
            $summary = $this->compactor->compact($space, $session, $ref, $data['focus'] ?? null, $data['range'] ?? 'older', $data['turn'] ?? null);

            return $this->console($space, $ref, $subject, $this->sessions->open($space, $session, $ref)) + ['compacted' => $summary !== null];
        });
    }

    /**
     * The AI recap of a session for a person coming back to it (1.60.0). `recap: null` with `unavailable`
     * when it could not be written: the panel keeps the recap it drew from the turns, and a recap nobody
     * asked for by name never shows an error.
     */
    public function recap(Request $request, string $kind, string $id, string $session): JsonResponse
    {
        $data = $request->validate(['away_minutes' => ['nullable', 'integer', 'min:0', 'max:525600']]);

        return $this->on($request, $kind, $id, function (string $space, string $ref, TerminalSubject $subject) use ($data, $session) {
            try {
                return ['recap' => $this->recaps->recap($space, $session, $ref, $subject->label($ref), isset($data['away_minutes']) ? (int) $data['away_minutes'] : null), 'unavailable' => false];
            } catch (AiRequestRefusedException $e) {
                // Not this person's session, or not this subject's: a 404 like every other route.
                if ($e->status === 404) {
                    throw $e;
                }
                report($e);

                return ['recap' => null, 'unavailable' => true];
            } catch (Throwable $e) {
                report($e);

                return ['recap' => null, 'unavailable' => true];
            }
        });
    }

    /** Rewind the conversation to before an ask (the product restores the design itself). */
    public function rewind(Request $request, string $kind, string $id, string $session): JsonResponse
    {
        $data = $request->validate(['turn' => ['required', 'uuid']]);

        return $this->on($request, $kind, $id, function (string $space, string $ref, TerminalSubject $subject) use ($data, $session) {
            $marked = $this->sessions->markRewound($space, $session, $ref, $data['turn']);

            return $this->console($space, $ref, $subject, $this->sessions->open($space, $session, $ref)) + ['rewound' => $marked];
        });
    }

    /** `/brief`: the subject's standing instructions, shared by everyone working on it. Empty removes it. */
    public function brief(Request $request, string $kind, string $id): JsonResponse
    {
        $data = $request->validate(['text' => ['present', 'nullable', 'string', 'max:'.TerminalSessions::BRIEF_MAX]]);

        return $this->on($request, $kind, $id, function (string $space, string $ref) use ($data, $request) {
            $user = $request->user();
            $by = $user === null ? null : (string) ($user->name ?? $user->email ?? 'Someone');

            return ['brief' => $this->sessions->setBrief($ref, $data['text'] ?? null, $by)];
        });
    }

    /**
     * The person's specialization choice (1.64): a guide's key, `none` to follow no guide, or null to leave it
     * to the subject (its own kind, or the assistant's pick). Only a subject that is a {@see SpecializedSubject}
     * has one, and only a guide it offers may be chosen.
     */
    public function specialization(Request $request, string $kind, string $id): JsonResponse
    {
        $data = $request->validate(['key' => ['present', 'nullable', 'string', 'regex:'.TerminalSessions::SPECIALIZATION_PATTERN]]);

        return $this->on($request, $kind, $id, function (string $space, string $ref, TerminalSubject $subject) use ($data, $request) {
            if (! $subject instanceof SpecializedSubject) {
                throw new AiRequestRefusedException('Not found', 404);
            }

            $key = $data['key'] ?? null;
            $offered = array_column($subject->specializations($ref), 'key');
            if ($key !== null && $key !== 'none' && ! in_array($key, $offered, true)) {
                throw new AiRequestRefusedException('That specialization is not available here. Choose one from the list.', 422);
            }

            $user = $request->user();
            $by = $user === null ? null : (string) ($user->name ?? $user->email ?? 'Someone');
            $choice = $this->sessions->setSpecialization($ref, $key, $by);

            // 1.65: a new guide brings its own templates, so the console redraws them in the same answer.
            return ['choice' => $choice, 'specialization' => $subject->specialization($ref, $choice), 'specializations' => $subject->specializations($ref), ...self::templateOf($subject, $ref, $this->sessions)];
        });
    }

    /**
     * The template this subject follows inside its guide (1.65): a template's key, `none` for the guide's own
     * sections, or null to leave it to the assistant's pick again. Only a {@see TemplatedSubject} has one, only
     * while a guide applies, and only a template it offers may be chosen.
     */
    public function template(Request $request, string $kind, string $id): JsonResponse
    {
        $data = $request->validate(['key' => ['present', 'nullable', 'string', 'regex:'.TerminalSessions::TEMPLATE_PATTERN]]);

        return $this->on($request, $kind, $id, function (string $space, string $ref, TerminalSubject $subject) use ($data, $request) {
            if (! $subject instanceof TemplatedSubject) {
                throw new AiRequestRefusedException('Not found', 404);
            }

            $key = $data['key'] ?? null;
            $guide = $subject->templateGuide($ref);
            if ($key !== null && $guide === null) {
                throw new AiRequestRefusedException('No guide applies here, so there is no template to choose. Choose a guide first.', 422);
            }
            $offered = array_column($subject->templates($ref), 'key');
            if ($key !== null && $key !== 'none' && ! in_array($key, $offered, true)) {
                throw new AiRequestRefusedException('That template is not available here. Choose one from the list.', 422);
            }

            $user = $request->user();
            $by = $user === null ? null : (string) ($user->name ?? $user->email ?? 'Someone');
            $choice = $this->sessions->setTemplate($ref, $guide, $key, $by, 'person');

            return ['choice' => $choice, 'template' => $subject->template($ref, $choice), 'templates' => $subject->templates($ref)];
        });
    }

    /**
     * What the console draws about templates (1.65); nothing for a subject that offers none.
     *
     * @return array<string, mixed>
     */
    private static function templateOf(TerminalSubject $subject, string $ref, TerminalSessions $sessions): array
    {
        if (! $subject instanceof TemplatedSubject) {
            return [];
        }

        $choice = $sessions->templateChoice($ref);

        return ['template' => $subject->template($ref, $choice), 'templates' => $subject->templates($ref), 'template_choice' => $choice];
    }

    /**
     * Everything the panel draws, in one shape.
     *
     * @param  array{session: array<string, mixed>, turns: list<array<string, mixed>>}|null  $open
     */
    private function console(string $space, string $ref, TerminalSubject $subject, ?array $open): array
    {
        $brief = $this->sessions->brief($ref);
        $sizing = $subject->sizing($ref);
        $window = SessionWindow::build($open['session'] ?? [], $open['turns'] ?? [], $brief);

        return [
            'subject' => ['ref' => $ref, 'label' => $subject->label($ref)],
            'session' => $open === null ? null : self::sessionRow($open['session']),
            'turns' => array_map(fn (array $t) => self::turnRow($t), $open['turns'] ?? []),
            'brief' => $brief,
            'size' => SessionWindow::size($window, (int) ($sizing['design_chars'] ?? 0), (int) ($sizing['budget_tokens'] ?? 1)),
            'history' => $this->history($space, $ref, $open),
            ...self::specializationOf($subject, $ref, $this->sessions),
        ];
    }

    /**
     * What the console draws about specializations (1.64); nothing for a subject that has none.
     *
     * @return array<string, mixed>
     */
    private static function specializationOf(TerminalSubject $subject, string $ref, TerminalSessions $sessions): array
    {
        if (! $subject instanceof SpecializedSubject) {
            return [];
        }

        $choice = $sessions->specializationChoice($ref);

        return ['specialization' => $subject->specialization($ref, $choice), 'specializations' => $subject->specializations($ref), 'specialization_choice' => $choice, ...self::templateOf($subject, $ref, $sessions)];
    }

    /** The subject's asks, newest first, for the input's up-arrow: this session first, then the ones before. */
    private function history(string $space, string $ref, ?array $open): array
    {
        $asks = [];
        $add = function (array $turns) use (&$asks) {
            foreach (array_reverse($turns) as $t) {
                $body = trim((string) ($t['body'] ?? ''));
                if (($t['role'] ?? '') === 'user' && $body !== '' && ! in_array($body, $asks, true)) {
                    $asks[] = $body;
                }
            }
        };

        $add($open['turns'] ?? []);
        $currentId = $open['session']['id'] ?? null;

        foreach ($this->sessions->list($space, $ref, self::HISTORY_SESSIONS + 1) as $s) {
            if (count($asks) >= self::HISTORY_MAX) {
                break;
            }
            if (($s['id'] ?? null) === $currentId) {
                continue;
            }
            $add($this->sessions->open($space, (string) $s['id'], $ref)['turns']);
        }

        return array_slice($asks, 0, self::HISTORY_MAX);
    }

    public static function sessionRow(array $s): array
    {
        $summary = TerminalSessions::summary($s);

        return [
            'id' => (string) ($s['id'] ?? ''),
            'title' => isset($s['title']) && $s['title'] !== '' ? (string) $s['title'] : null,
            'started_at' => $s['created_at'] ?? null,
            'last_at' => $s['last_turn_at'] ?? $s['created_at'] ?? null,
            'turns' => (int) ($s['turn_count'] ?? 0),
            'summary' => $summary === null ? null : [
                'text' => (string) $summary['text'],
                'scope' => (string) ($summary['scope'] ?? 'before'),
                'turns_folded' => (int) ($summary['turns_folded'] ?? 0),
                'at' => $summary['at'] ?? null,
                'folded' => array_values(array_map('strval', (array) ($summary['folded'] ?? []))),
            ],
        ];
    }

    public static function turnRow(array $t): array
    {
        return [
            'id' => (string) ($t['id'] ?? ''),
            'role' => (string) ($t['role'] ?? ''),
            'body' => $t['body'] ?? null,
            'structured' => is_array($t['structured'] ?? null) ? $t['structured'] : null,
            'rewound' => TerminalSessions::rewound($t),
            'at' => $t['created_at'] ?? null,
        ];
    }

    /**
     * The subject this request's console serves (1.72.0): the one its mount names (`TerminalRoutes::mount(...,
     * $subject)`), else the product's bound `TerminalSubject`, else none (every route 404s).
     */
    public static function subjectFor(Request $request, Container $app): ?TerminalSubject
    {
        $class = $request->route()?->defaults[TerminalRoutes::SUBJECT] ?? null;

        if (is_string($class) && $class !== '') {
            return $app->make($class);
        }

        return $app->bound(TerminalSubject::class) ? $app->make(TerminalSubject::class) : null;
    }

    private function on(Request $request, string $kind, string $id, Closure $work): JsonResponse
    {
        $subject = self::subjectFor($request, $this->app);

        if ($subject === null) {
            return $this->fail('Not found', 404);
        }

        try {
            $ref = $subject->ref($request, $kind, $id);
            if ($ref === null) {
                return $this->fail('Not found', 404);
            }

            $space = $this->spaces->current($request);
            if ($space === null) {
                return $this->fail('Not found', 404);
            }

            return response()->json(['success' => true, 'message' => 'Success', 'data' => $work($space, $ref, $subject)]);
        } catch (AiRequestRefusedException $e) {
            return $this->fail($e->getMessage(), $e->status >= 400 && $e->status < 500 ? $e->status : 422);
        } catch (AiUnavailableException $e) {
            report($e);

            return $this->fail('The console\'s history is not reachable right now. Try again in a moment.', 503);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('That did not work. Try again in a moment.', 500);
        }
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
