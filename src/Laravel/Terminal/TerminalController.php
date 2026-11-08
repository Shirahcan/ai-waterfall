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
    ) {}

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
        ];
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

    private function on(Request $request, string $kind, string $id, Closure $work): JsonResponse
    {
        if (! $this->app->bound(TerminalSubject::class)) {
            return $this->fail('Not found', 404);
        }

        try {
            $subject = $this->app->make(TerminalSubject::class);
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
