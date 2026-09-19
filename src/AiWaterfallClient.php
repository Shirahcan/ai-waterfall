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
        ]);
    }

    /** The estate's credential picture, including the shared bench. */
    public function credentialStatus(): array
    {
        return $this->get('api/v1/credentials/status');
    }

    /** Tokens, spend and savings. No argument means the combined estate view. */
    public function usage(array $query = []): array
    {
        return $this->get('api/v1/usage', $query);
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
        $body = array_filter($body, fn ($v) => $v !== null && $v !== []);

        try {
            $response = $this->http->post('api/v1/generate', [
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
