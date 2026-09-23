<?php

namespace Shirahcan\AiWaterfall;

use GuzzleHttp\Client as Guzzle;
use Shirahcan\AiWaterfall\Exceptions\AiOverBudgetException;
use Shirahcan\AiWaterfall\Exceptions\AiUnavailableException;
use Shirahcan\AiWaterfall\Exceptions\AllProvidersFailedException;
use Shirahcan\AiWaterfall\Exceptions\NoCompliantCredentialException;
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
class AiWaterfallClient
{
    public function __construct(
        private string $baseUrl,
        private string $trustKey,
        private int $timeout = 60,
        private ?Guzzle $http = null,
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
        $body['per_provider_timeout'] = $this->perProviderTimeout;

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

        throw match ($decoded['error'] ?? null) {
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
            default => new AiUnavailableException(
                ($decoded['message'] ?? 'ai-service returned an error').' (HTTP '.$status.')'
            ),
        };
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
