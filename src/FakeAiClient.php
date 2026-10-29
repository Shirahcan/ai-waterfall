<?php

namespace Shirahcan\AiClient;

use Shirahcan\AiClient\Exceptions\AdminNotConfiguredException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Throwable;

/**
 * A test double, so every product can exercise its AI paths with no service, no
 * keys and no network.
 *
 * ⚠ THIS IS PART OF THE CONTRACT, NOT A CONVENIENCE. Three products are about to
 * depend on one service; if testing an AI path required that service to be
 * running, each product's suite would become flaky in a way that has nothing to
 * do with the code under test - and the honest response to a flaky suite is to
 * stop running it. Portify's FakeAIProviderAdapter played exactly this role.
 *
 * Queue results (or Throwables) in the order they should be returned:
 *
 *   $fake->queue(['action' => 'ask']);
 *   $fake->queueFailure(new AiUnavailableException('down'));
 */
class FakeAiClient extends AiClient
{
    /** @var array<int, mixed> */
    private array $queue = [];

    /**
     * The shared record of what this fake was asked to do.
     *
     * ⚠ AN OBJECT, BECAUSE `withBudget()` AND `withCorrelation()` CLONE. A
     * clone copies arrays by value, so every call recorded after a `with*()`
     * landed on the clone and was invisible from the instance the test held -
     * and it failed as a FALSE NEGATIVE: "the AI was never called" about working
     * code, and an unconditional pass for any test asserting it was NOT called.
     * An object is shared by handle, so the log is the same log.
     */
    public FakeCallLog $log;

    /**
     * @deprecated Read {@see $log}. Kept so existing assertions on `->calls`
     *   keep working, and it now returns the SHARED log rather than this
     *   instance's private copy.
     *
     * @return array<int, array{task: string, mode: string, payload: mixed}>
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'calls'    => $this->log->calls,
            'searches' => $this->log->searches,
            default    => null,
        };
    }

    /** The correlation bag of the last call, or null if there was none. */
    public function correlation(): array
    {
        return $this->log->correlation ?? [];
    }

    public function __construct()
    {
        $this->log = new FakeCallLog();

        // Deliberately does NOT call parent::__construct: there is no base URL and
        // no trust key, and a fake that needed either would defeat its own purpose.
    }

    public function queue(mixed $result): static
    {
        $this->queue[] = $result;

        return $this;
    }

    public function queueFailure(Throwable $e): static
    {
        $this->queue[] = $e;

        return $this;
    }

    /**
     * ⚠ RETURNS ITSELF, UNLIKE THE REAL CLIENT, AND THAT IS DELIBERATE.
     *
     * AiClient::withBudget() returns a CLONE, which is right for real
     * use - a per-call budget must not leak into the next caller. But a test
     * holds ONE fake and asserts against its recorded $calls, so a clone would
     * silently record the call on an object the test never sees and every
     * assertion would read "no calls" while the code under test worked
     * perfectly. That failure looks like a broken seam and is really a broken
     * double.
     *
     * The budget itself is not modelled here: what a test needs to know is which
     * calls were made, and the real client's own tests cover the wire shape.
     */
    public function withBudget(?float $budgetSeconds, ?int $perProviderTimeout = null): static
    {
        return $this;
    }

    public function generateJson(string $task, string $system, string $prompt, ?int $maxRepairs = null): AiResult
    {
        return $this->next($task, 'json', $prompt);
    }

    public function generateText(string $task, string $system, string $prompt): AiResult
    {
        return $this->next($task, 'text', $prompt);
    }

    /**
     * Events the next `generateStream()` will yield.
     *
     * ⚠ QUEUE THE EVENTS, NOT THE FINISHED TEXT. A fake that only let a test say
     * "the answer is X" would make every consumer look correct, because the two
     * things that actually go wrong in a stream cannot be expressed: a `discard`
     * after partial output, and a stream that ends with no terminal event. Both
     * are the defects worth testing for, so both must be queueable.
     *
     * @var array<int, AiStreamEvent>
     */
    private array $streamEvents = [];

    /** @var array<int, string> Stream ids this fake was asked to cancel. */
    public array $cancelledStreams = [];

    /** Convenience: tokens plus the terminal `done`, the ordinary happy path. */
    public function queueStreamText(string $text, string $streamId = 'stream-1'): static
    {
        $this->streamEvents = [new AiStreamEvent(AiStreamEvent::OPEN, ['stream_id' => $streamId])];

        foreach (str_split($text, 8) as $chunk) {
            $this->streamEvents[] = new AiStreamEvent(AiStreamEvent::TOKEN, ['text' => $chunk]);
        }

        $this->streamEvents[] = new AiStreamEvent(AiStreamEvent::DONE, ['provider' => 'fake', 'model' => 'fake']);

        return $this;
    }

    /** @param  array<int, AiStreamEvent>  $events */
    public function queueStreamEvents(array $events): static
    {
        $this->streamEvents = $events;

        return $this;
    }

    /** @return iterable<AiStreamEvent> */
    public function generateStream(
        string $task,
        string $system,
        string $prompt,
        ?string $field = null,
        ?callable $onOpen = null,
    ): iterable {
        $this->log->calls[] = ['task' => $task, 'mode' => 'stream', 'payload' => $prompt];

        $events = $this->streamEvents;
        $this->streamEvents = [];

        foreach ($events as $event) {
            if ($event->type === AiStreamEvent::OPEN && $onOpen !== null) {
                $id = $event->streamId();
                if ($id !== null) {
                    $onOpen($id);
                }
            }

            yield $event;
        }
    }

    public function cancelStream(string $streamId): bool
    {
        $this->cancelledStreams[] = $streamId;

        return true;
    }

    public function generateStructured(
        string $task,
        string $system,
        array $messages,
        array $schema = [],
        string $schemaName = 'structured_output',
        array $requiredKeys = [],
        ?int $maxRepairs = null,
    ): AiResult {
        return $this->next($task, 'structured', [
            'system' => $system, 'messages' => $messages,
            'schema' => $schema, 'schema_name' => $schemaName,
            'required_keys' => $requiredKeys,
        ]);
    }

    public function generateFromMedia(
        string $task,
        string $system,
        string $prompt,
        array $media,
        ?int $maxRepairs = null,
    ): AiResult {
        /*
         * ⚠ The MIMES are logged, the BYTES are not. A test double that keeps a
         * client's passport in a public array would put it in every failure dump
         * and every assertion diff the suite ever prints.
         */
        return $this->next($task, 'media', [
            'system' => $system,
            'prompt' => $prompt,
            'media_mimes' => array_column($media, 'mime'),
            'media_count' => count($media),
        ]);
    }

    /**
     * Queue a plain vector (model "fake-embedding"), an AiEmbedding, or a
     * Throwable, in the same queue as every other call.
     */
    public function embed(string $task, string $text, bool $sensitive = false): AiEmbedding
    {
        $this->log->calls[] = ['task' => $task, 'mode' => 'embed', 'payload' => ['chars' => mb_strlen($text), 'sensitive' => $sensitive]];

        if ($this->queue === []) {
            throw new AiUnavailableException('FakeAiClient queue exhausted for task ['.$task.']');
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next instanceof AiEmbedding
            ? $next
            : new AiEmbedding(vector: array_map('floatval', (array) $next), model: 'fake-embedding', provider: 'fake');
    }

    public function credentialStatus(): array
    {
        return ['credentials' => [], 'benched' => 0];
    }

    /** @var list<array<string, mixed>> attempts a test seeds for usageCalls() */
    public array $usageCallRows = [];

    public function usageCalls(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $rows = array_values(array_filter($this->usageCallRows, function (array $r) use ($filters) {
            foreach (['task', 'provider', 'outcome'] as $f) {
                $want = (string) ($filters[$f] ?? '');
                if ($want === '') {
                    continue;
                }
                // `!x` means "anything but x", exactly as the service reads it.
                $match = str_starts_with($want, '!') ? ($r[$f] ?? null) !== substr($want, 1) : ($r[$f] ?? null) === $want;
                if (! $match) {
                    return false;
                }
            }

            return true;
        }));

        return ['calls' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'meta' => [
            'page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
        ]];
    }

    public function usage(array $query = []): array
    {
        return ['totals' => ['calls' => count($this->log->calls)]];
    }

    /*
    |--------------------------------------------------------------------------
    | ⚠ THE REPORTING SURFACE, WHICH THIS FAKE USED TO LEAVE UNCOVERED
    |--------------------------------------------------------------------------
    |
    | `usageReport()`, `myUsageReport()` and `modelStatus()` were NOT overridden
    | here, so they fell through to the parent and made LIVE HTTP CALLS from
    | inside a test. Measured consequence: Portify's ConsoleUsageTest asserted
    | that an admin console reported 3 AI calls and was handed **2,398** by a
    | reachable ai-service, and had been testing the network rather than the
    | controller ever since that console moved off the local ledger.
    |
    | A fake that silently falls through to the real transport is worse than no
    | fake at all, because it makes a networked test look isolated. This
    | package's own README calls the fake "part of the contract, not a
    | convenience", and a contract with holes in it is just a habit.
    |
    */

    /** @var array<string, mixed>|null */
    private ?array $usageReportPayload = null;

    /** @var array<int, array<string, mixed>> */
    private array $statusModels = [];

    /**
     * Queue the payload the service would have returned, in its wire shape.
     *
     * ⚠ `by_outcome` IS A MAP while `by_provider` and `by_tier` are LISTS of
     * rows - the service's own asymmetry (`UsageController::outcomes()` plucks,
     * `grouped()` returns rows). AiUsageReport reads each faithfully, so a
     * fixture that picks one shape for all three reports zero failures with no
     * error at all.
     *
     * @param  array<string, mixed>  $payload
     */
    public function queueUsageReport(array $payload): static
    {
        $this->usageReportPayload = $payload;

        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $models
     *
     * ⚠ A ROW WITHOUT A `health` KEY MEANS "THE SERVICE DOES NOT REPORT TIER
     * HEALTH", which is a real state a product must handle (an un-deployed box)
     * and NOT the same as a healthy one. A fixture that omits it on purpose is
     * testing that path; a fixture that omits it by accident will find
     * `hasRedundancy()` returning null, which is the fake refusing to invent
     * a verdict rather than quietly handing back a green one.
     */
    public function queueModelStatus(array $models): static
    {
        $this->statusModels = $models;

        return $this;
    }

    public function usageReport(array $query = [], ?int $cacheSeconds = null): AiUsageReport
    {
        return AiUsageReport::fromArray($this->usageReportPayload ?? $this->emptyReport());
    }

    public function myUsageReport(array $query = [], ?int $cacheSeconds = null): AiUsageReport
    {
        return AiUsageReport::fromArray($this->usageReportPayload ?? $this->emptyReport());
    }

    public function modelStatus(?int $cacheSeconds = 30): AiModelStatus
    {
        /*
         * ⚠ THE WIRE KEY IS `credentials`, NOT `models`. AiModelStatus::fromArray
         * reads $payload['credentials'], so a fixture keyed 'models' produces a
         * report of zero credentials that looks perfectly valid - which is
         * exactly the kind of quietly-empty fixture a fake exists to prevent.
         */
        return AiModelStatus::fromArray(['credentials' => $this->statusModels]);
    }

    /**
     * An honest EMPTY report rather than an invented one.
     *
     * ⚠ It reports zero calls and says so, so a test that forgot to queue a
     * payload sees zeros and fails its assertion, instead of being handed
     * plausible numbers it then asserts nothing useful about.
     *
     * @return array<string, mixed>
     */
    private function emptyReport(): array
    {
        return [
            'from'   => '1970-01-01T00:00:00+00:00',
            'to'     => '1970-01-01T00:00:00+00:00',
            'totals' => ['calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'spend_micros' => 0],
            'by_provider' => [], 'by_task' => [], 'by_model' => [],
            'by_tier' => [], 'by_outcome' => [],
            'latency' => ['p50' => 0, 'p95' => 0],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Research
    |--------------------------------------------------------------------------
    */

    /** @var array<int, array<string, mixed>|Throwable> */
    private array $searchQueue = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $pages = [];



    /*
    |--------------------------------------------------------------------------
    | Transcription
    |--------------------------------------------------------------------------
    */

    /** @var array<int, array<string, mixed>|Throwable> */
    private array $transcriptions = [];

    /** @var list<array{filename: string, task: string, bytes: int}> What was sent to be transcribed. */
    public array $transcribed = [];

    public function queueTranscription(string $text, ?float $durationSeconds = null, string $provider = 'fake-whisper'): static
    {
        $this->transcriptions[] = [
            'text' => $text, 'duration_seconds' => $durationSeconds, 'language' => null,
            'provider' => $provider, 'latency_ms' => 10,
        ];

        return $this;
    }

    public function queueTranscriptionFailure(Throwable $e): static
    {
        $this->transcriptions[] = $e;

        return $this;
    }

    /**
     * ⚠ AN EMPTY QUEUE REFUSES, like the real service with no cleared key. A test that forgot to
     * queue a transcript exercises the refusal path instead of receiving invented speech.
     */
    public function transcribe(string $bytes, string $filename, string $task, ?string $language = null): array
    {
        $this->transcribed[] = ['filename' => $filename, 'task' => $task, 'bytes' => strlen($bytes)];
        $next = array_shift($this->transcriptions);

        if ($next === null) {
            throw new \Shirahcan\AiClient\Exceptions\AllProvidersFailedException('No transcription credential cleared for personal data is configured.', []);
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @param array<int, array<string, mixed>> $results */
    public function queueSearch(array $results, string $provider = 'fake-search', bool $cached = false): static
    {
        // `cached` mirrors the service (1.24): true when it answered from its own memory, unbilled.
        $this->searchQueue[] = [
            'results' => $results, 'provider' => $provider,
            'latency_ms' => 10, 'attempts' => [], 'cached' => $cached,
        ];

        return $this;
    }

    public function queueSearchFailure(Throwable $e): static
    {
        $this->searchQueue[] = $e;

        return $this;
    }

    /**
     * Register what a URL fetches to. An unregistered URL returns null, which
     * is what the real service does for anything unsafe or unreachable - so a
     * test that forgot to register one exercises the REFUSAL path rather than
     * silently receiving invented page text.
     *
     * @param  array<string, mixed>|null  $page
     */
    public function queuePage(string $url, ?array $page): static
    {
        $this->pages[$url] = $page;

        return $this;
    }

    public function research(): AiResearch
    {
        return new FakeAiResearch($this);
    }

    /**
     * Record the bag on the SHARED log, then behave exactly as the real client
     * does by returning a clone.
     *
     * ⚠ RECORDED BEFORE THE CLONE, on purpose. Recording on the clone would
     * reproduce the very defect FakeCallLog exists to fix: the test holds the
     * original and would see nothing.
     */
    /**
     * The personas this fake pretends the service holds.
     *
     * ⚠ ON THE SHARED LOG, like everything else here, because withPersona()
     * and withBudget() clone. A persona published through a clone and then
     * asserted on the original is exactly the false negative FakeCallLog
     * exists to stop.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $personas = [];

    /** @param array<int, array<string, mixed>> $personas */
    public function queuePersonas(array $personas): static
    {
        $this->personas = $personas;

        return $this;
    }

    public function personas(): AiPersonas
    {
        return new FakeAiPersonas($this, $this->personas);
    }

    /**
     * The in-memory vault this fake pretends the service holds.
     *
     * ⚠ ONE INSTANCE, HELD FOR THE LIFE OF THE FAKE. A fresh FakeAiAdmin per
     * call would forget every write, so a console test that added a credential
     * and then listed them would see an empty vault - and would read as a bug
     * in the console rather than in the double.
     */
    private ?FakeAiAdmin $admin = null;

    /**
     * ⚠ WHETHER THIS PRODUCT IS CONFIGURED TO ADMINISTER AT ALL, modelled on
     * purpose. The real client THROWS when no `AI_SERVICE_ADMIN_KEY` is set,
     * and a fake that always allowed `admin()` would hide the one mistake that
     * is easiest to make in deployment: shipping the console to a product whose
     * env never got the key. See {@see withoutAdminKey()}.
     */
    private bool $hasAdminKey = true;

    /**
     * Behave like a product that was never given an admin key.
     *
     * This is how a console's "you are not authorised to manage the vault"
     * branch gets exercised, and that branch matters: without it the screens
     * render empty and an operator concludes the vault is empty.
     */
    public function withoutAdminKey(): static
    {
        $this->hasAdminKey = false;

        return $this;
    }

    public function canAdminister(): bool
    {
        return $this->hasAdminKey;
    }

    /**
     * The in-memory knowledge index this fake pretends the service holds.
     *
     * ⚠ ONE INSTANCE, FOR THE SAME REASON THE VAULT IS ONE: a fresh double
     * per call would forget every sync, so a test that synced and then
     * retrieved would find nothing - and would read as a bug in the product
     * rather than in the double.
     */
    private ?FakeAiKnowledge $knowledge = null;

    public function knowledge(): AiKnowledge
    {
        return $this->knowledge ??= new FakeAiKnowledge();
    }

    /**
     * The in-memory conversation store this fake pretends the service holds.
     *
     * ⚠ ONE INSTANCE, for the same reason the knowledge index is one: a fresh double per call
     * would forget every thread, so a test that started a conversation and then appended to it
     * would get "no such conversation" and read as a bug in the product.
     */
    private ?FakeAiConversations $conversations = null;

    /** Whether the fake should behave as though ai-service cannot be reached. */
    private bool $conversationsUnavailable = false;

    public function conversations(): AiConversations
    {
        /*
         * ⚠ THE OUTAGE BRANCH HAS TO BE REACHABLE, AND IT IS THE ONE PRODUCTS GET WRONG. Since
         * the conversation store became the authority for a product's transcripts, "what happens
         * when ai-service cannot be reached" stopped being a tidiness question: it decides
         * whether a person's own message is written at all, whether a thread list renders, and
         * whether an erasure a client asked for actually happened. A fake with no way to say
         * "unavailable" leaves every one of those branches untested, and they are the branches
         * that only run on the worst day.
         *
         * Thrown HERE rather than inside each method because this is the seam a product crosses:
         * `AiUnavailableException` from the accessor is exactly what a resolution-time failure
         * looks like in production.
         */
        if ($this->conversationsUnavailable) {
            throw new AiUnavailableException('ai-service is unreachable: the fake was told to pretend so.');
        }

        if ($this->conversations === null) {
            $this->conversations = new FakeAiConversations();
            // The service erases memory with a person's conversations; so does the fake.
            $this->conversations->memory = $this->memory();
        }

        return $this->conversations;
    }

    private ?FakeAiMemoryStore $memoryStore = null;

    /** The memory store, faked: one instance per fake client, shared by every call. */
    public function memory(): FakeAiMemoryStore
    {
        if ($this->conversationsUnavailable) {
            throw new AiUnavailableException('ai-service is unreachable: the fake was told to pretend so.');
        }

        return $this->memoryStore ??= new FakeAiMemoryStore();
    }

    /**
     * Pretend ai-service cannot be reached for conversations.
     *
     * ⚠ IT DOES NOT DISCARD WHAT THE FAKE ALREADY HOLDS, so a test can take the service away
     * and give it back - which is the shape of a real outage, and the only way to test that a
     * product recovers rather than merely that it survives.
     */
    public function pretendConversationsUnavailable(bool $unavailable = true): static
    {
        $this->conversationsUnavailable = $unavailable;

        return $this;
    }

    /**
     * The fake store itself, for the setup and assertions the typed surface does not expose:
     * `pretendRetentionErased()`, `forgetTurn()`, `turnCount()`, `$writes`.
     */
    public function transcripts(): FakeAiConversations
    {
        $this->conversations();

        return $this->conversations;
    }

    public function admin(): AiAdmin
    {
        if (! $this->hasAdminKey) {
            throw AdminNotConfiguredException::make();
        }

        return $this->admin ??= new FakeAiAdmin();
    }

    /**
     * The same vault instance, for seeding and for asserting on writes.
     *
     * Named differently from `admin()` so a test reads honestly: `admin()` is
     * the surface under test, `vault()` is the fixture behind it.
     */
    public function vault(): FakeAiAdmin
    {
        $this->hasAdminKey = true;

        return $this->admin ??= new FakeAiAdmin();
    }

    /** Which voice the LAST call asked to speak in, or null. */
    public function persona(): ?array
    {
        return $this->log->persona;
    }

    public function withPersona(string $persona, ?string $audience = null): static
    {
        // Recorded BEFORE the clone, or the test holding the original sees nothing.
        $this->log->persona = ['persona' => $persona, 'audience' => $audience];

        return parent::withPersona($persona, $audience);
    }

    public function withCorrelation(array $correlation): static
    {
        $this->log->correlation = $correlation === [] ? null : $correlation;

        return parent::withCorrelation($correlation);
    }

    public function withRoute(string $routeKey): static
    {
        // Recorded BEFORE the clone, or the test holding the original sees nothing.
        $this->log->routeKey = $routeKey === '' ? null : $routeKey;

        return parent::withRoute($routeKey);
    }

    public function withPreferredModels(array $pairs): static
    {
        // Recorded BEFORE the clone, or the test holding the original sees nothing.
        $this->log->preferredModels = $pairs === [] ? null : array_values($pairs);

        return parent::withPreferredModels($pairs);
    }

    /**
     * @internal for FakeAiResearch
     *
     * @return array<string, mixed>
     */
    public function nextSearch(string $task, string $query): array
    {
        $this->log->searches[] = ['task' => $task, 'query' => $query];

        if ($this->searchQueue === []) {
            // Same rule as the generation queue: an exhausted fake throws
            // rather than inventing results a test would then assert on.
            throw new AiUnavailableException('FakeAiClient search queue exhausted for ['.$query.']');
        }

        $next = array_shift($this->searchQueue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @internal for FakeAiResearch
     *
     * @return array<string, mixed>|null
     */
    public function pageFor(string $url): ?array
    {
        return $this->pages[$url] ?? null;
    }

    public function isReachable(): bool
    {
        return true;
    }

    private function next(string $task, string $mode, mixed $payload): AiResult
    {
        $this->log->calls[] = ['task' => $task, 'mode' => $mode, 'payload' => $payload];

        if ($this->queue === []) {
            /*
             * ⚠ An exhausted queue THROWS rather than returning something empty.
             * A fake that quietly invents a result lets a test pass while asserting
             * nothing, which is worse than no test at all.
             */
            throw new AiUnavailableException('FakeAiClient queue exhausted for task ['.$task.']');
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next instanceof AiResult ? $next : new AiResult(data: $next, provider: 'fake', model: 'fake');
    }
}
