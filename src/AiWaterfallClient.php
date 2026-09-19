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
     * A provider-shaped payload the CALLER built.
     *
     * This is how Porter keeps its conversational turn - Gemini `contents` is not
     * OpenAI `messages` - without the service ever learning what a Porter turn is.
     * `requiredKeys` drives the service's schema-repair retry without it needing to
     * know what those keys MEAN.
     */
    public function invokeRaw(string $task, array $payload, array $requiredKeys = []): AiResult
    {
        return $this->call([
            'task' => $task, 'mode' => 'raw',
            'payload' => $payload, 'required_keys' => $requiredKeys,
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
