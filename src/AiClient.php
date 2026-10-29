<?php

namespace Shirahcan\AiClient;

use GuzzleHttp\Client as Guzzle;
use Shirahcan\AiClient\Exceptions\AdminNotConfiguredException;
use Shirahcan\AiClient\Exceptions\AiOverBudgetException;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Shirahcan\AiClient\Exceptions\NoCompliantCredentialException;
use Throwable;

/**
 * Talks to ai-service over loopback.
 *
 * ⚠ IT HOLDS NO PROVIDER KEYS, AND MUST NEVER LEARN HOW TO. A client that carried
 * its own keys as a "fallback" would recreate the per-product duplication this
 * whole programme exists to end, and would do it invisibly - working fine right
 * up until two products were benching the same provider quota separately again.
 * If the service is unreachable, that is an outage to report, not a reason to
 * route around it.
 *
 * ⚠ Its config is base_url, trust_key and timeout. NOTHING ELSE. The moment a
 * product's config names a provider or a model, that product has an opinion about
 * routing and the single source of truth has leaked back out.
 */
class AiClient
{
    public function __construct(
        private string $baseUrl,
        private string $trustKey,
        private int $timeout = 60,
        private ?Guzzle $http = null,
        /**
         * ⚠ A SEPARATE KEY FOR ADMINISTERING THE VAULT, AND USUALLY UNSET.
         *
         * Only a product with an AI admin console needs one. It must NOT be the
         * same string as `$trustKey`: that key travels on every generation call
         * this product makes, so it is the one most likely to leak - one already
         * did, into a chat transcript, on 2026-10-12 - and it must therefore be
         * able to do the least. See {@see admin()}.
         */
        private string $adminKey = '',
    ) {
        $this->http ??= new Guzzle([
            'base_uri' => rtrim($this->baseUrl, '/').'/',
            'timeout'  => $this->timeout,
        ]);
    }

    /**
     * The caller's latency caps for the next call, or null to leave them to the
     * service.
     *
     * ⚠ THESE ARE NOT ADVISORY, AND LOSING THEM IS A REAL DEFECT. A caller that
     * caps a provider at 6 seconds is protecting an INLINE request: blow the
     * budget and it takes a gateway timeout, which kills its own "degrade to
     * something deterministic" branch before that branch can run. Until these
     * crossed the wire the service silently substituted its own 30s default, so
     * every such cap was quietly discarded the moment a product migrated.
     */
    private ?float $budgetSeconds = null;
    private ?int $perProviderTimeout = null;

    public function withBudget(?float $budgetSeconds, ?int $perProviderTimeout = null): static
    {
        $clone = clone $this;
        $clone->budgetSeconds = $budgetSeconds;
        $clone->perProviderTimeout = $perProviderTimeout;

        return $clone;
    }

    /** @var array<int, array{provider: string, model?: string}>|null */
    private ?array $preferredModels = null;

    private ?string $routeKey = null;

    /**
     * Ask the service to try these (provider, model) pairs FIRST.
     *
     * ⚠ THE SERVICE HAS HONOURED THIS SINCE IT WAS WRITTEN AND NO CLIENT COULD
     * SEND IT. Its executor reorders eligible credentials by preference; its
     * controller never read the field off the wire, so the capability existed
     * and was unreachable from outside.
     *
     * Portify's case-outcome verdict is the caller that needs it: a PAID deep
     * verdict escalates the model by case complexity, and from the day Portify's
     * seam shipped (2026-09-19) that escalation was dropped at the boundary - a
     * client paid for a deeper read and got whatever answered first, which on
     * that bench is a free flash-lite rung. Found 2026-10-22.
     *
     * ⚠ ADVISORY, NEVER AUTHORISING. It only REORDERS credentials the walk was
     * already allowed to use, so it cannot reach a disabled credential, cannot
     * escape the paid-tier restriction a sensitive task carries, and cannot
     * clear anything for client documents. A pair with no eligible credential is
     * skipped. Ask for a stronger model; you cannot ask for a forbidden one.
     *
     * ⚠ A CLONE, LIKE withBudget(). The client is a singleton, so a preference
     * set in place would push one caller's model choice onto the next request
     * handled by the same worker.
     *
     * @param  array<int, array{provider: string, model?: string}>  $pairs
     */
    public function withPreferredModels(array $pairs): static
    {
        $clone = clone $this;
        $clone->preferredModels = $pairs === [] ? null : array_values($pairs);

        return $clone;
    }

    /**
     * Use a NAMED preference ladder for this call (`assess.case.outcome:deep`).
     *
     * PREFER THIS TO withPreferredModels(). A route is decided on the service's
     * vault console, so a product names WHICH ladder it wants and an admin
     * decides which models stand on it - moving a tier from one model to another
     * is then a screen change, not a deploy of every product that named the
     * model in its own code. An explicit withPreferredModels() still wins.
     *
     * A CLONE, like every other with*(): the client is a singleton.
     */
    public function withRoute(string $routeKey): static
    {
        $clone = clone $this;
        $clone->routeKey = $routeKey === '' ? null : $routeKey;

        return $clone;
    }

    /**
     * The caller's own identifiers, recorded on every usage row this call makes.
     *
     * ⚠ WHY THE SERVICE NEEDS THIS AT ALL. `ai_usage` carried product, task,
     * provider, model, tier, tokens, cost and outcome - but nothing saying WHOSE
     * question a row was. So Portify kept a second ledger (11,853 rows against
     * the service's 3,547) with its own cost calculator and its own copy of the
     * rate table, purely to answer "what did this case cost to run". Two copies
     * of a changeable fact is the defect shape this estate keeps paying for.
     *
     * ⚠ IDENTIFIERS, NOT CONTENT, AND THE SERVICE ENFORCES THAT. The service
     * stores no prompts and no completions, deliberately - client documents and
     * supplier invoices pass through it, and a usage table holding their text
     * would be a second, unscanned copy of the estate's most sensitive data. The
     * bag is capped at 10 keys of 80 characters, so a sentence does not fit. Put
     * ids in it, never a question or a name.
     *
     * ⚠ `_key` IS THE ONE INDEXED VALUE, AND YOU CHOOSE IT. Only you know
     * which of your ids is worth asking questions about later, and the service
     * must not learn what a `case_id` means - the moment it does, it has an
     * opinion about your domain.
     *
     * ⚠ A CLONE, LIKE withBudget(). The client is a singleton, so mutating in
     * place would leak one user's correlation onto the next request handled by
     * the same worker - which is worse than no correlation at all, because the
     * row would be confidently attributed to the wrong person.
     *
     * @param  array<string, string>  $correlation  e.g.
     *   `['_key' => 'case:9e3f', 'user_id' => '…', 'source' => 'web']`
     */
    public function withCorrelation(array $correlation): static
    {
        $clone = clone $this;
        $clone->correlation = $correlation === [] ? null : $correlation;

        return $clone;
    }

    /** @var array<string, string>|null */
    private ?array $correlation = null;

    /** Forced-JSON generation from a system + user prompt. */
    public function generateJson(string $task, string $system, string $prompt, ?int $maxRepairs = null): AiResult
    {
        return $this->call([
            'task' => $task, 'mode' => 'json', 'system' => $system,
            'prompt' => $prompt, 'max_repairs' => $maxRepairs,
        ]);
    }

    /** Plain prose, for when the OUTPUT is itself a markup language. */
    public function generateText(string $task, string $system, string $prompt): AiResult
    {
        return $this->call(['task' => $task, 'mode' => 'text', 'system' => $system, 'prompt' => $prompt]);
    }

    /**
     * The same generation, streamed token by token.
     *
     * ⚠ THIS EXISTS SO NOBODY HAND-ROLLS IT. A product that wants streaming and
     * finds no method here will reach for the loopback URL directly, and in
     * doing so re-implement the trust key, the base URL, the product identity,
     * the socket timeouts and the error translation - all five of which live in
     * this class precisely so there is ONE of each. The moment two callers reach
     * the service two different ways, every future change to the wire has two
     * homes and they drift. If streaming needs something this method lacks, add
     * it HERE.
     *
     * ⚠ `$field` IS THE COMMON CASE, NOT THE EXCEPTION. Almost every AI call on
     * this estate asks for JSON and reads one key out of it - Porter's composer
     * says `Return ONLY JSON: {"answer": string}`. Pass `field: 'answer'` and the
     * service streams the characters of that value as they are generated, rather
     * than streaming `{"ans`. Omit it only when the whole output is prose.
     *
     * ⚠ THE RETURNED GENERATOR DRIVES THE SOCKET. Nothing is read until it is
     * iterated, and abandoning it mid-way leaves the request open until the
     * connection is collected - call {@see cancelStream()} if you stop early,
     * which is what makes a stop REAL rather than a disconnect the provider
     * never hears about.
     *
     * @param  ?callable(string $streamId): void  $onOpen  Receives the stream id
     *   the moment it arrives, so a cancel is possible from the very next tick
     *   rather than only after the first token.
     * @return iterable<AiStreamEvent>
     *
     * @throws AiUnavailableException When the service is unreachable, refuses
     *   the request, or the stream ends without a terminal event.
     */
    public function generateStream(
        string $task,
        string $system,
        string $prompt,
        ?string $field = null,
        ?callable $onOpen = null,
    ): iterable {
        $body = array_filter([
            'task'                 => $task,
            'system'               => $system,
            'prompt'               => $prompt,
            'field'                => $field,
            'budget_seconds'       => $this->budgetSeconds,
            'correlation'          => $this->correlation,
            'persona'              => $this->persona,
            'audience'             => $this->personaAudience,
            'per_provider_timeout' => $this->perProviderTimeout,
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $response = $this->http->post('api/v1/generate/stream', [
                'headers'     => $this->headers() + ['Accept' => 'text/event-stream'],
                'json'        => $body,
                'http_errors' => false,
                /*
                 * ⚠ BOTH OF THESE, OR IT IS NOT A STREAM. `stream => true` stops
                 * Guzzle buffering the whole body before returning; `timeout => 0`
                 * stops the socket being cut while the stream is legitimately open.
                 * A stream is bounded by its own terminal event and by the caller
                 * cancelling, never by a fixed wall clock measured from the start.
                 */
                'stream'      => true,
                'timeout'     => 0,
                'read_timeout' => $this->timeout,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            // A refusal arrives as ordinary JSON, not as SSE.
            $decoded = json_decode((string) $response->getBody(), true) ?: [];

            throw $this->errorFor($status, $decoded);
        }

        yield from $this->readEvents($response->getBody(), $onOpen);
    }

    /**
     * Stop a stream at the provider, not just at this socket.
     *
     * ⚠ HANGING UP IS NOT STOPPING. Dropping the connection leaves the provider
     * call running to completion and billed - the work is done, nobody reads it,
     * and the money is spent anyway. This tells the service to stop, which is the
     * difference between a Stop button that means something and one that only
     * changes what the user can see.
     *
     * Safe to call for a stream that has already finished; that is a `false`, not
     * an error, so a fire-and-forget cancel on an already-complete turn is fine.
     */
    public function cancelStream(string $streamId): bool
    {
        try {
            $response = $this->http->delete('api/v1/generate/stream/'.rawurlencode($streamId), [
                'headers'     => $this->headers(),
                'http_errors' => false,
                'timeout'     => 5,
            ]);
        } catch (Throwable) {
            /*
             * ⚠ A FAILED CANCEL MUST NOT PROPAGATE. The caller has already told
             * its user the turn is stopped; throwing here would either resurrect
             * a spinner or surface an error for something the user did on
             * purpose. Report false and let the stream's own terminal event or
             * the dropped socket end it.
             */
            return false;
        }

        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        return (bool) ($decoded['cancelled'] ?? false);
    }

    /**
     * Run one chat turn in ai-service's chat engine (`POST /v1/chat/turns`).
     *
     * The service runs a tool-using loop and calls THIS product's tools back with
     * the input's `toolToken`; this method streams its events to `$onEvent` as
     * they arrive (token, tool, card, discard) and returns the finished turn.
     *
     * ⚠ `discard` MEANS UN-PAINT what was shown for the current step: a provider
     * died mid-answer and the next one restarts it. A consumer that keeps
     * appending shows two answers glued together.
     *
     * ⚠ STOP IT WITH {@see cancelStream()} and the id `$onOpen` received: the turn
     * shares the stream registry, so the same cancel stops a turn at the provider.
     *
     * @param  ?callable(AiStreamEvent $event): void  $onEvent
     * @param  ?callable(string $streamId): void  $onOpen
     *
     * @throws AiUnavailableException When the service is unreachable, refuses, errors
     *   mid-turn, or the stream ends without `final`.
     */
    public function chatTurn(Chat\ChatTurnInput $input, ?callable $onEvent = null, ?callable $onOpen = null): Chat\ChatTurnResult
    {
        $body = $input->toWire() + array_filter(['correlation' => $this->correlation], fn ($v) => $v !== null && $v !== []);

        try {
            $response = $this->http->post('api/v1/chat/turns', [
                'headers'      => $this->headers() + ['Accept' => 'text/event-stream'],
                'json'         => $body,
                'http_errors'  => false,
                // As generateStream: a stream ends on its terminal event, never on a wall clock.
                'stream'       => true,
                'timeout'      => 0,
                'read_timeout' => $this->timeout,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->errorFor($status, json_decode((string) $response->getBody(), true) ?: []);
        }

        return $this->consumeTurn($this->readEvents($response->getBody(), $onOpen), $onEvent);
    }

    /**
     * Fold a chat turn's events into its result. Shared with the fake, so a test
     * exercises the same reading of `final` / `error` / `cancelled` as production.
     *
     * @param  iterable<AiStreamEvent>  $events
     */
    protected function consumeTurn(iterable $events, ?callable $onEvent): Chat\ChatTurnResult
    {
        $streamId = null;

        foreach ($events as $event) {
            if ($event->type === AiStreamEvent::OPEN) {
                $streamId = $event->streamId();
            }

            if ($onEvent !== null) {
                $onEvent($event);
            }

            if ($event->type === AiStreamEvent::FINAL) {
                return Chat\ChatTurnResult::fromFinal($event->data, $streamId);
            }

            if ($event->type === AiStreamEvent::ERROR || $event->type === AiStreamEvent::CANCELLED) {
                throw new AiUnavailableException('The chat turn did not finish: '
                    .(string) ($event->data['message'] ?? $event->data['reason'] ?? $event->type));
            }
        }

        throw new AiUnavailableException('The chat turn ended without completing.');
    }

    /**
     * Parse an SSE body into typed events.
     *
     * ⚠ FRAMES SPLIT ACROSS CHUNKS, AND A PARSER THAT ASSUMES OTHERWISE WORKS ON
     * LOCALHOST AND CORRUPTS ON A REAL NETWORK. `data: {"te` and `xt":"hi"}`
     * routinely arrive as two reads. So bytes are buffered until a blank line
     * terminates a frame, never parsed per chunk.
     *
     * @return iterable<AiStreamEvent>
     */
    private function readEvents(mixed $stream, ?callable $onOpen): iterable
    {
        $buffer = '';
        $sawTerminal = false;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            // Normalise CRLF so a proxy that rewrites line endings cannot hide a
            // frame boundary from the split below.
            $buffer = str_replace("\r\n", "\n", $buffer);

            while (($cut = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $cut);
                $buffer = substr($buffer, $cut + 2);

                $event = $this->parseFrame($frame);

                if ($event === null) {
                    continue;
                }

                if ($event->type === AiStreamEvent::OPEN && $onOpen !== null) {
                    $id = $event->streamId();
                    if ($id !== null) {
                        $onOpen($id);
                    }
                }

                yield $event;

                if ($event->isTerminal()) {
                    $sawTerminal = true;

                    return;
                }
            }
        }

        if (! $sawTerminal) {
            /*
             * ⚠ A STREAM THAT JUST STOPS IS A FAILURE, NOT A SUCCESS. Without
             * this the caller sees the tokens it already had, no error, and a
             * truncated half-answer that reads as finished prose - and acts on
             * it. Ending without `done` / `cancelled` / `error` means the
             * connection died mid-answer, and that has to be said out loud.
             */
            throw new AiUnavailableException('The stream ended without completing.');
        }
    }

    private function parseFrame(string $frame): ?AiStreamEvent
    {
        $type = null;
        $data = '';

        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, ':')) {
                continue; // a keep-alive comment
            }

            if (str_starts_with($line, 'event:')) {
                $type = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                // Multiple data: lines concatenate, per the SSE spec.
                $data .= ($data === '' ? '' : "\n").trim(substr($line, 5));
            }
        }

        if ($type === null || $type === '') {
            return null;
        }

        $decoded = $data === '' ? [] : (json_decode($data, true) ?: []);

        return new AiStreamEvent($type, is_array($decoded) ? $decoded : []);
    }

    /**
     * A multi-turn conversation with YOUR JSON schema enforced.
     *
     * This is how a caller keeps a structured, stateful exchange - Porter's intake
     * turn, a finance assistant's verdict - without the service ever learning what
     * that structure MEANS.
     *
     * ⚠ THE PAYLOAD IS PROVIDER-NEUTRAL, AND IT HAS TO BE. An earlier version of
     * this method was called invokeRaw() and took "a provider-shaped payload the
     * CALLER built". That cannot work across a waterfall: you do not know whether
     * Claude, Gemini or Grok will answer, and their wire formats are mutually
     * invalid. A payload shaped for one is an HTTP 400 from the others, which the
     * waterfall reads as "that provider failed" - so a single shape mismatch walks
     * the whole ladder and comes back as "all providers failed".
     *
     * Send the system prompt and an ordered list of {role, content} turns. Each
     * adapter inside the service projects that into its own format.
     *
     * @param  list<array{role: string, content: string}>  $messages  Roles are `user`/`assistant`.
     * @param  array<string, mixed>  $schema  Your JSON Schema. The service never owns one.
     * @param  string  $schemaName  The model SEES this (it becomes Claude's tool
     *   name), so if your system prompt says "call the submit_next_turn tool", pass
     *   that exact name here or the instruction points at nothing.
     * @param  list<string>  $requiredKeys  Drives the schema-repair retry. The
     *   service enforces presence; the SEMANTIC check stays with you.
     */
    public function generateStructured(
        string $task,
        string $system,
        array $messages,
        array $schema = [],
        string $schemaName = 'structured_output',
        array $requiredKeys = [],
        ?int $maxRepairs = null,
    ): AiResult {
        return $this->call([
            'task'          => $task,
            'mode'          => 'structured',
            'system'        => $system,
            'messages'      => array_values($messages),
            'json_schema'   => $schema,
            'schema_name'   => $schemaName,
            'required_keys' => $requiredKeys,
            'max_repairs'   => $maxRepairs,
        ]);
    }

    /**
     * Forced-JSON generation where the model READS attached images/PDFs directly,
     * instead of OCR text.
     *
     * ⚠ THIS SENDS THE DOCUMENT ITSELF. For Portify that means a real client's
     * passport, bank statement or IRCC letter crossing the hop as bytes. The
     * service treats the task as SENSITIVE when it is listed in its
     * `sensitive_tasks` config, and then only a credential a human has explicitly
     * cleared may serve it - so an uncleared estate REFUSES with
     * NoCompliantCredentialException rather than quietly falling back to a free
     * training-tier provider. Treat that refusal as the control working.
     *
     * A provider with no vision support is SKIPPED, exactly as in-process, so a
     * mixed bench degrades to its vision-capable rungs rather than failing.
     *
     * @param  list<array{mime: string, bytes: string, filename?: ?string}>  $media
     *   Raw bytes, NOT base64; this encodes them.
     */
    public function generateFromMedia(
        string $task,
        string $system,
        string $prompt,
        array $media,
        ?int $maxRepairs = null,
    ): AiResult {
        return $this->call([
            'task'        => $task,
            'mode'        => 'media',
            'system'      => $system,
            'prompt'      => $prompt,
            'media'       => array_values(array_map(fn (array $m) => [
                'mime'     => $m['mime'],
                'data'     => base64_encode($m['bytes']),
                'filename' => $m['filename'] ?? null,
            ], $media)),
            'max_repairs' => $maxRepairs,
            /*
             * ⚠ ALWAYS TRUE FOR MEDIA, REGARDLESS OF THE TASK NAME. A call that
             * attaches a document IS a call that sends a document, whatever it is
             * called - so this does not wait for the task to appear on the
             * service's sensitive list. The flag can only TIGHTEN; a task already
             * listed there stays sensitive whatever a caller sends.
             */
            'sensitive'   => true,
        ]);
    }

    /**
     * Text in, vector out, from the estate's ONE embedding model.
     *
     * ⚠ THE SERVICE CHOOSES THE MODEL, NOT YOU. The result names it; keep that
     * name beside every stored vector and rebuild your index when it changes.
     * There is deliberately no `$model` parameter: a product that picks its own
     * embedding model has an opinion about routing, which is the leak this
     * package exists to prevent.
     *
     * The input is REFUSED (422, surfaced as AiUnavailableException), not
     * truncated, when it exceeds the service's limit. Where to cut a long
     * document is your decision.
     *
     * @throws AiUnavailableException|AllProvidersFailedException|AiOverBudgetException|NoCompliantCredentialException
     */
    public function embed(string $task, string $text, bool $sensitive = false): AiEmbedding
    {
        $body = array_filter([
            'task'           => $task,
            'input'          => $text,
            'sensitive'      => $sensitive ?: null,
            'budget_seconds' => $this->budgetSeconds,
            'correlation'    => $this->correlation,
        ], fn ($v) => $v !== null);

        $options = [];
        if ($this->budgetSeconds !== null) {
            $options['timeout'] = $this->budgetSeconds + 15;
        }

        try {
            $response = $this->http->post('api/v1/embed', $options + [
                'headers'     => $this->headers(),
                'json'        => $body,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            return AiEmbedding::fromArray($decoded);
        }

        throw $this->errorFor($status, $decoded);
    }

    /** The estate's credential picture, including the shared bench. */
    /**
     * Which models are usable right now, as a typed report.
     *
     * ⚠ PREFER THIS OVER credentialStatus(). The raw array leaves every product
     * to work out for itself whether a rung is usable, and three mechanisms can
     * take one out of rotation - a rate-limit bench, an open circuit, a learned
     * prompt-size ceiling. Four products deriving that independently is four
     * chances to disagree about one credential.
     *
     * Cached like the usage report and for the same reason: a status tile on a
     * dashboard should not put the service under load on every page render. The
     * TTL is short by default because a bench can clear at any moment.
     */
    public function modelStatus(?int $cacheSeconds = 30): AiModelStatus
    {
        if ($cacheSeconds === null || $cacheSeconds <= 0) {
            return AiModelStatus::fromArray($this->credentialStatus());
        }

        return AiModelStatus::fromArray(
            $this->remember('ai_waterfall:model_status', $cacheSeconds, fn () => $this->credentialStatus())
        );
    }

    public function credentialStatus(): array
    {
        return $this->get('api/v1/credentials/status');
    }

    /**
     * Tokens, spend and savings, as the raw response. No argument means the
     * combined estate view.
     *
     * Prefer {@see usageReport()} in product code: a typed carrier stops every
     * caller indexing the same string keys and drifting when a key is renamed.
     * This stays for anything that genuinely wants the untouched payload.
     */
    /**
     * This product's AI attempts, line by line, newest first (P21h). One row per provider attempt.
     *
     * @param  array{days?: int, task?: string, provider?: string, outcome?: string}  $filters
     * @return array{calls: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function usageCalls(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $payload = $this->get('api/v1/usage/calls', array_filter($filters, fn ($v) => $v !== null && $v !== '') + ['page' => $page, 'per_page' => $perPage]);

        return ['calls' => array_values((array) ($payload['calls'] ?? [])), 'meta' => (array) ($payload['meta'] ?? [])];
    }

    public function usage(array $query = []): array
    {
        return $this->get('api/v1/usage', $query);
    }

    /**
     * The same figures, typed.
     *
     * ⚠ THE SERVICE COMPUTES, THE PRODUCT READS. This is the single source of
     * truth for "what did AI cost": the service owns the rates, the credential
     * TIER that served each call, and therefore the only correct answer. A
     * product cannot know the tier and so cannot price its own calls - Portify
     * tried, and its ledger reported $114.71 of spend from free providers that
     * never charged a penny.
     *
     * ⚠ NO ARGUMENT IS THE ESTATE VIEW. `['product' => 'portify']` narrows it.
     * One shape answers both questions, because two shapes are two places for
     * the same figure to drift.
     *
     * ⚠ CACHING IS OPT-IN AND STALENESS IS VISIBLE. A cached report carries the
     * `to` timestamp from the moment it was FETCHED, not the moment it is read,
     * so a surface showing it is showing its own as-of date without needing a
     * separate "cached at" field to be plumbed and kept honest.
     *
     * Use a TTL for anything a user loads repeatedly - a dashboard hitting the
     * service on every page render turns a reporting call into traffic. Leave it
     * null for a CLI or a one-off, where a fresh read costs nothing.
     *
     * @param  array<string,mixed>  $query  product, task, provider, from, to
     * @param  int|null  $cacheSeconds  null fetches every time
     */
    public function usageReport(array $query = [], ?int $cacheSeconds = null): AiUsageReport
    {
        if ($cacheSeconds === null || $cacheSeconds <= 0) {
            return AiUsageReport::fromArray($this->usage($query));
        }

        return AiUsageReport::fromArray(
            $this->remember($this->usageCacheKey($query), $cacheSeconds, fn () => $this->usage($query))
        );
    }

    /**
     * Cache read-through that FAILS OPEN.
     *
     * ⚠ A CACHE FAULT MUST NOT BECOME A REPORTING OUTAGE. This package runs in
     * four products with different cache drivers, and one misconfigured store
     * should degrade to a slower correct answer, never to no answer. Any
     * throwable from the cache falls through to a direct fetch.
     *
     * @param  callable():array<string,mixed>  $fetch
     * @return array<string,mixed>
     */
    private function remember(string $key, int $seconds, callable $fetch): array
    {
        try {
            if (! class_exists(\Illuminate\Support\Facades\Cache::class)) {
                return $fetch();
            }

            $cached = \Illuminate\Support\Facades\Cache::get($key);

            if (is_array($cached)) {
                return $cached;
            }

            $fresh = $fetch();

            \Illuminate\Support\Facades\Cache::put($key, $fresh, $seconds);

            return $fresh;
        } catch (Throwable) {
            return $fetch();
        }
    }

    /**
     * ⚠ THE QUERY IS PART OF THE KEY. Two different windows or product filters
     * are two different answers, and sharing one key would serve the estate
     * total to a caller that asked for one product - a wrong number presented
     * with total confidence.
     *
     * @param  array<string,mixed>  $query
     */
    private function usageCacheKey(array $query): string
    {
        ksort($query);

        return 'ai_waterfall:usage:'.md5((string) json_encode($query));
    }

    /**
     * This product's own usage, without it having to know its own name.
     *
     * ⚠ THE PRODUCT NAME COMES FROM THE TRUST KEY, NOT FROM CONFIG. A product
     * that spells its own name in a query can spell it wrong - and `finance` vs
     * `finance-suite` has already been exactly that trap in this estate. The
     * service resolves the caller from the key it authenticated, so asking it
     * "who am I" cannot disagree with who it just decided you were.
     *
     * @param  array<string,mixed>  $query  additional filters: task, from, to
     * @param  int|null  $cacheSeconds  null fetches every time
     */
    public function myUsageReport(array $query = [], ?int $cacheSeconds = null): AiUsageReport
    {
        $product = (string) ($this->get('api/v1/whoami')['product'] ?? '');

        if ($product === '') {
            throw new AiUnavailableException(
                'ai-service did not name this caller, so its own usage cannot be scoped. '
                .'Use usageReport(["product" => ...]) if you must name it yourself.'
            );
        }

        return $this->usageReport($query + ['product' => $product], $cacheSeconds);
    }

    /** Is the service reachable at all? Never throws; for health surfaces. */
    public function isReachable(): bool
    {
        try {
            $this->get('api/health');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function call(array $body): AiResult
    {
        $body['budget_seconds']       = $this->budgetSeconds;
        $body['correlation']          ??= $this->correlation;
        $body['persona']              ??= $this->persona;
        $body['audience']             ??= $this->personaAudience;
        $body['per_provider_timeout'] = $this->perProviderTimeout;
        $body['preferred_models']     = $this->preferredModels;
        $body['route_key']            = $this->routeKey;

        $body = array_filter($body, fn ($v) => $v !== null && $v !== []);

        /*
         * ⚠ THE SOCKET MUST OUTLIVE THE WORK BY A MARGIN, NOT BY NOTHING. If the
         * HTTP timeout equalled the waterfall budget, a run that used its full
         * budget would be cut off by this client a fraction before the service
         * returned its answer - and the caller would see a connection failure
         * where the estate had actually succeeded. The margin covers the hop and
         * the service's own bookkeeping.
         */
        $options = [];
        if ($this->budgetSeconds !== null) {
            $options['timeout'] = $this->budgetSeconds + 15;
        }

        try {
            $response = $this->http->post('api/v1/generate', $options + [
                'headers' => $this->headers(),
                'json'    => $body,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            /*
             * ⚠ THE SERVICE BEING DOWN MUST LOOK LIKE TODAY'S FAILURE, not a new
             * one. Every existing caller already catches "all providers failed" and
             * falls back to a deterministic path; handing them an unfamiliar
             * exception would turn a degraded feature into an unhandled error.
             */
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            return AiResult::fromArray($decoded);
        }

        throw $this->errorFor($status, $decoded);
    }

    /**
     * Translate a service refusal into the exception its caller already handles.
     *
     * ⚠ ONE TRANSLATION, SHARED BY THE BUFFERED AND STREAMING PATHS. This used to
     * live inline in `call()`; the moment `generateStream()` needed the same
     * mapping it became a fact with two homes, and the two would have drifted the
     * first time a new `error` code was added to the service.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function errorFor(int $status, array $decoded): Throwable
    {
        return match ($decoded['error'] ?? null) {
            // Distinct on purpose: "raise the cap or find the loop" is a different
            // job from "the providers are down", and a caller that cannot tell them
            // apart sends an operator to the wrong place.
            'over_budget' => new AiOverBudgetException($decoded['message'] ?? 'Over budget.'),
            'no_compliant_credential' => new NoCompliantCredentialException(
                $decoded['message'] ?? 'No credential is cleared for this sensitive task.'
            ),
            'all_providers_failed' => new AllProvidersFailedException(
                $decoded['message'] ?? 'Every provider refused.',
                $decoded['attempts'] ?? []
            ),
            /*
             * ⚠ A PAGE THAT CANNOT BE FETCHED IS NOT AN OUTAGE. The research
             * fetch endpoint answers 422 `not_fetchable` for an unsafe,
             * unreachable or empty page, and that is a fact about one URL.
             * Mapped to AiUnavailableException it would read as "ai-service is
             * down" and every caller's outage branch would fire for a single
             * bad link.
             */
            'not_fetchable' => new AllProvidersFailedException(
                $decoded['message'] ?? 'That page could not be fetched.',
                []
            ),

            /*
             * ⚠ A SEARCH-SPECIFIC 503 IS AN OUTAGE WITH A DIFFERENT REMEDY:
             * add a `kind = search` credential. It is not folded into
             * `no_credential` because an operator told "no credential" looks at
             * the generation bench, which is full and healthy and has nothing
             * to do with it.
             */
            'no_search_credential' => new AllProvidersFailedException(
                $decoded['message'] ?? 'No web-search credential is configured.',
                []
            ),

            // Same shape as search: the remedy is a `kind = transcription` key cleared for personal
            // data, not anything on the generation bench.
            'no_transcription_credential' => new AllProvidersFailedException(
                $decoded['message'] ?? 'No transcription credential cleared for personal data is configured.',
                []
            ),

            /*
             * ⚠ A QUERY THAT COULD NOT BE EMBEDDED IS NOT AN OUTAGE OF THIS
             * SERVICE, and the remedy is the same as every provider refusing:
             * fall back to lexical retrieval. Left on `default` it arrived as
             * "ai-service returned an error (HTTP 503)", which sends a reader to
             * check whether the service is up - it is - instead of at the
             * embedding credential that actually refused.
             */
            'embedding_unavailable' => new AllProvidersFailedException(
                $decoded['message'] ?? 'The query could not be embedded.',
                $decoded['attempts'] ?? []
            ),

            default => $status >= 400 && $status < 500
                /*
                 * ⚠ THE SERVICE ANSWERED AND REFUSED: it is healthy and the
                 * request was wrong, and its own message usually says exactly
                 * what to fix. Reported as an outage, that message was thrown
                 * away. The refusal type SUBCLASSES AiUnavailableException, so
                 * every existing degrade path still catches it unchanged.
                 */
                ? new AiRequestRefusedException(
                    $decoded['message'] ?? 'ai-service refused the request (HTTP '.$status.').',
                    $status,
                    isset($decoded['error']) ? (string) $decoded['error'] : null,
                )
                : new AiUnavailableException(
                    ($decoded['message'] ?? 'ai-service returned an error').' (HTTP '.$status.')'
                ),
        };
    }

    /**
     * Web search and page fetching.
     *
     * A separate surface rather than more methods here, so a caller cannot
     * reach for `generateJson()` with a search task and be charged for a walk
     * down the wrong ladder - and so a product testing research does not have
     * to queue generation results into the fake.
     */
    public function research(): AiResearch
    {
        return new AiResearch($this);
    }

    /**
     * Speech to text through the vault (service P20f2, 2026-10-24).
     *
     * ⚠ ONLY KEYS CLEARED FOR PERSONAL DATA ARE USED. A recording is a person speaking, so with no
     * cleared transcription key the service refuses (AllProvidersFailedException) rather than send
     * the audio to a provider nobody decided may hold it.
     *
     * @return array{text: string, duration_seconds: ?float, language: ?string, provider: string, latency_ms: int}
     *
     * @throws AiUnavailableException|AllProvidersFailedException|AiOverBudgetException
     */
    public function transcribe(string $bytes, string $filename, string $task, ?string $language = null): array
    {
        $multipart = [
            ['name' => 'task', 'contents' => $task],
            ['name' => 'audio', 'contents' => $bytes, 'filename' => $filename],
        ];

        if ($language !== null && $language !== '') {
            $multipart[] = ['name' => 'language', 'contents' => $language];
        }

        try {
            $response = $this->http->post('api/v1/transcribe', [
                'headers'     => $this->headers(),
                'multipart'   => $multipart,
                'http_errors' => false,
                // Audio takes longer than a prompt; the provider's own limit is the real ceiling.
                'timeout'     => max($this->timeout, 180),
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            return [
                'text'             => (string) ($decoded['text'] ?? ''),
                'duration_seconds' => isset($decoded['duration_seconds']) ? (float) $decoded['duration_seconds'] : null,
                'language'         => isset($decoded['language']) ? (string) $decoded['language'] : null,
                'provider'         => (string) ($decoded['provider'] ?? ''),
                'latency_ms'       => (int) ($decoded['latency_ms'] ?? 0),
            ];
        }

        throw $this->errorFor($status, $decoded);
    }

    /**
     * The stored voice this call should speak in, and to whom.
     *
     * The CALLER names it; the SERVICE owns the text. That split is the point:
     * the product knows which of its assistants is talking and to which role,
     * and the service holds the words so the person who owns them can edit them
     * rather than wait for a deploy.
     *
     * ⚠ AUDIENCE IS SEPARATE, BECAUSE ROLE-BLIND PERSONA IS THE RECURRING
     * DEFECT. A practising consultant was told "I am software, not a person, and
     * not your consultant" - correct for a client, wrong for the professional
     * who IS the consultant. One voice for everybody is how that happens.
     *
     * ⚠ A CLONE, LIKE withBudget(). The client is a singleton, so mutating in
     * place would put one audience voice into the next request handled by the
     * same worker.
     *
     * ⚠ DO NOT SET IT FOR AN EXTRACTION OR A CLASSIFICATION. Those want a
     * machine-readable answer; house style there buys nothing and costs tokens
     * on every call.
     */
    public function withPersona(string $persona, ?string $audience = null): static
    {
        $clone = clone $this;
        $clone->persona = $persona;
        $clone->personaAudience = $audience;

        return $clone;
    }

    private ?string $persona = null;
    private ?string $personaAudience = null;

    /** Manage this product stored voices. */
    public function personas(): AiPersonas
    {
        return new AiPersonas($this);
    }

    /**
     * One assistant turn: plan, use the product's own tools, answer.
     *
     * ⚠ THE LOOP RUNS HERE, IN THE PRODUCT'S PROCESS, AND THE TOOLS RUN HERE
     * TOO. ai-service decides WHAT to do next; this product decides whether the
     * viewer may do it and then does it. Letting the service drive the loop and
     * call back would give the box that holds every AI provider key in the
     * estate the ability to act AS A USER inside every product - and would give
     * each product's permission model two enforcers.
     *
     * ⚠ THE PERSONA IS NAMED HERE AND OWNED BY THE SERVICE. Pass the key and
     * the audience; the voice itself comes from the persona registry at the
     * service's own choke point, so changing how an assistant sounds is a row,
     * not a deploy.
     *
     * @param  string  $task  the usage-ledger task name for every step of the turn
     */
    public function assistant(
        string $persona,
        ?string $audience = null,
        string $system = '',
        string $task = 'assistant.turn',
    ): Assistant\AssistantTurn {
        return new Assistant\AssistantTurn(
            // Persona and audience ride on a CLONE, like every other per-call
            // setting here: the client is a singleton, and one audience's voice
            // leaking into the next request would be a user hearing an answer
            // written for somebody else's role.
            $this->withPersona($persona, $audience),
            $task,
            $system,
        );
    }

    /**
     * This product's knowledge SERVING INDEX in the service: sync it, prune it,
     * search it semantically.
     *
     * ⚠ THE AUTHORITY STAYS IN YOUR PRODUCT. What is true - and whether an
     * entry still names a route that exists - belongs where your own tests can
     * fail on it. This is the index built from that corpus, which lives beside
     * the one embedding model because vectors from two models are not
     * comparable and the failure is silent.
     */
    public function knowledge(): AiKnowledge
    {
        return new AiKnowledge($this);
    }

    /**
     * This product's conversations: start a thread, append a turn, read a page, erase.
     *
     * ⚠ THE SERVICE HOLDS THE TRANSCRIPT AND CANNOT IDENTIFY THE PERSON. `owner_ref` is YOUR
     * id for them and nothing in the service can map it to a human - what is centralised is
     * the text, not the identity. So authorisation stays yours: the trust key proves which
     * PRODUCT is calling, never which person, and you decide who may read a thread exactly as
     * you decide who may read a case.
     *
     * ⚠ THIS IS A STORE, NOT A CACHE. Unlike {@see knowledge()}, where the authority stays in
     * your product and only the index lives in the service, here the service IS the authority -
     * that is the estate's own pattern, proven in calendar-service and video-service, and
     * `Meeting.php` says so in its own docblock. Keep your row's own foreign keys and this
     * thread's id; let the summary scalars hydrate.
     *
     * ⚠ AND THE TRANSCRIPT IS ENCRYPTED AT REST, WITH A RETENTION WINDOW THAT RUNS. Both were
     * conditions, not features: a store that kept everything for ever would be a liability
     * holding a client's immigration history on the box that holds every provider key. Expect
     * a thread to be GONE one day, and render that rather than reporting it.
     */
    public function conversations(): AiConversations
    {
        return new AiConversations($this);
    }

    /**
     * What an assistant remembers about a person (P19e). Encrypted, scoped to this product's
     * trust key, and erased with the person's conversations.
     */
    public function memory(): AiMemoryStore
    {
        return new AiMemoryStore($this);
    }

    /**
     * The estate's AI vault: credentials, clearances, caps, the kill switch and
     * the trust keys other products call with.
     *
     * ⚠ IT USES A DIFFERENT KEY, AND THE SEPARATION IS THE SAFETY MODEL. The
     * ordinary trust key travels on every generation call this product makes -
     * it is the key most likely to leak, and one already did, into a chat
     * transcript, on 2026-10-12. If these endpoints answered to it, every
     * product's hot-path key would silently be an estate-wide root key able to
     * mint trust keys and clear a credential to receive client passports.
     *
     * ⚠ AND IT THROWS, RATHER THAN DEGRADING, WHEN NO ADMIN KEY IS SET. A
     * console with no key would otherwise show empty screens and an operator
     * would conclude the vault was empty. {@see AdminNotConfiguredException} is
     * a RuntimeException on purpose, so a product's AI degrade paths do not
     * absorb what is really a deployment mistake.
     */
    public function admin(): AiAdmin
    {
        if (trim($this->adminKey) === '') {
            throw AdminNotConfiguredException::make();
        }

        /*
         * A clone carrying the admin key as its bearer token, so every admin
         * call still goes through the one `errorFor()` mapping rather than a
         * second transport with its own idea of what a 403 means. Cloning also
         * keeps the admin key off the shared singleton: nothing that was handed
         * the client for generation can reach the vault with it.
         */
        $asAdmin = clone $this;
        $asAdmin->trustKey = $this->adminKey;

        return new AiAdmin($asAdmin);
    }

    /**
     * Can this product administer the vault at all?
     *
     * For a console to render "ask an operator with SSH access" instead of
     * throwing on page load - which is the right shape for a screen that a
     * product may legitimately not be authorised to show.
     */
    public function canAdminister(): bool
    {
        return trim($this->adminKey) !== '';
    }

    /** The caller's budget for the next call, for surfaces that forward it. */
    public function budgetSecondsOrNull(): ?float
    {
        return $this->budgetSeconds;
    }

    /**
     * POST a JSON body and return the decoded success payload.
     *
     * ⚠ PUBLIC BECAUSE THE SIBLING SURFACES NEED IT, AND SHARED BECAUSE THE
     * ERROR MAPPING MUST NOT BE WRITTEN TWICE. Every typed failure this package
     * promises - over budget, no compliant credential, all providers failed,
     * unreachable - is decided in `errorFor()`. A second surface that posted
     * with its own try/catch would map them slightly differently, and the
     * product's four carefully distinct `catch` blocks would start behaving
     * differently depending on which endpoint they came from. That is the
     * duplicate-derivation defect with an outage attached.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws AiUnavailableException|AllProvidersFailedException|AiOverBudgetException|NoCompliantCredentialException
     */
    public function postJson(string $path, array $body): array
    {
        $options = [];

        /*
         * The transport timeout sits ABOVE the caller's budget, never equal to
         * it. The service enforces the budget and answers; if the socket gave
         * up at the same instant the caller would see an unreachable service
         * instead of the real, attributed failure the service was about to
         * send.
         */
        if ($this->budgetSeconds !== null) {
            $options['timeout'] = $this->budgetSeconds + 15;
        }

        try {
            $response = $this->http->post($path, $options + [
                'headers'     => $this->headers(),
                'json'        => $body,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        throw $this->errorFor($status, $decoded);
    }

    /**
     * GET a JSON payload, with the same typed error mapping as postJson().
     *
     * ⚠ PUBLIC AND SHARED FOR THE SAME REASON postJson() IS: every typed
     * failure this package promises is decided in errorFor(), and a sibling
     * surface with its own try/catch would map them slightly differently - so a
     * product four carefully distinct catch blocks would behave differently
     * depending on which endpoint they came from.
     *
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        try {
            $response = $this->http->get($path, [
                'headers' => $this->headers(),
                'query' => $query,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        throw $this->errorFor($status, $decoded);
    }

    /**
     * PATCH a partial change, with the same typed error mapping.
     *
     * ⚠ PATCH RATHER THAN PUT FOR A REASON THE CALLER MUST KNOW: the service
     * treats absent fields as UNTOUCHED. Sending a whole row back is how a
     * console turns "disable this credential" into "blank its model and its
     * allow-list", which breaks the row for a reason unrelated to the button
     * that was pressed.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patchJson(string $path, array $body): array
    {
        return $this->send('patch', $path, $body);
    }

    /**
     * PUT a full replacement, with the same typed error mapping.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function putJson(string $path, array $body): array
    {
        return $this->send('put', $path, $body);
    }

    /**
     * ⚠ ONE PLACE THAT TURNS A RESPONSE INTO A RESULT OR A TYPED FAILURE. Every
     * promise this package makes is decided in `errorFor()`, so a sibling verb
     * with its own try/catch would map the same service reply slightly
     * differently - and a product's four carefully distinct `catch` blocks would
     * then behave differently depending on which endpoint they came from.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body): array
    {
        try {
            $response = $this->http->{$method}($path, [
                'headers'     => $this->headers(),
                'json'        => $body,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        throw $this->errorFor($status, $decoded);
    }

    /**
     * DELETE and return the decoded payload.
     *
     * ⚠ `$query` IS WHY A DELETE HERE CAN CARRY A FILTER. An erasure by person or by subject
     * has to say WHICH, and a DELETE body is the one request shape proxies and clients are
     * least consistent about forwarding. The service refuses a collection DELETE with no
     * filter rather than reading it as "everything", so a dropped parameter is a 422 and never
     * a wipe.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function deleteJson(string $path, array $query = []): array
    {
        try {
            $response = $this->http->delete($path, [
                'headers' => $this->headers(),
                'http_errors' => false,
                'query' => $query,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        throw $this->errorFor($status, $decoded);
    }

    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->http->get($path, ['headers' => $this->headers(), 'query' => $query]);
        } catch (Throwable $e) {
            throw new AiUnavailableException('ai-service is unreachable: '.$e->getMessage(), previous: $e);
        }

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /**
     * ⚠ The key goes in a header, never a query string. A key in a URL lands in
     * access logs and Referer headers, and this one opens the estate's provider
     * accounts.
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->trustKey, 'Accept' => 'application/json'];
    }
}
