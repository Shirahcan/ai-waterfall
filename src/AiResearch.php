<?php

namespace Shirahcan\AiClient;

use Shirahcan\AiClient\Exceptions\AiOverBudgetException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;

/**
 * Web search and page fetching, through the estate's search credentials.
 *
 * ⚠ IT SEARCHES AND FETCHES. IT DOES NOT RESEARCH. Grounding, synthesis,
 * citation validation and "is this source good enough to show a client" stay in
 * the product, because they are judgements about the product's own domain: a
 * regulated immigration answer and a supplier-credit check have opposite
 * standards for a good source. This returns pages and a bill.
 *
 * ⚠ WHY IT IS NOT JUST ANOTHER METHOD ON AiClient. A search key is not a
 * generation key, and keeping the two surfaces apart is what stops a caller
 * reaching for `generateJson()` with a search task and being charged for a
 * walk down the wrong ladder. The split is also how the FAKE stays honest: a
 * product testing research must not have to queue generation results.
 */
class AiResearch
{
    public function __construct(private AiClient $client) {}

    /**
     * Search the web.
     *
     * @param  array<int, string>  $includeDomains  Restrict to these hosts. The
     *   CALLER's judgement, deliberately: Portify pins a regulated question to
     *   canada.ca and the finance suite would not, so a default here would be a
     *   fifth place with an opinion about somebody else's domain.
     * @param  bool  $includeContent  Ask for page bodies as well as snippets.
     *   Off by default, because it multiplies the response several times over
     *   and a caller that only needs to CHOOSE a source should not pay for
     *   bodies it will discard.
     * @return array{results: array<int, array<string, mixed>>, provider: string, latency_ms: int, attempts: array<int, array<string, mixed>>, cached?: bool}
     *
     * @throws AiUnavailableException|AllProvidersFailedException|AiOverBudgetException
     */
    public function search(
        string $task,
        string $query,
        int $maxResults = 5,
        array $includeDomains = [],
        string $depth = 'basic',
        bool $includeContent = false,
    ): array {
        return $this->client->postJson('api/v1/research/search', array_filter([
            'task'            => $task,
            'query'           => $query,
            'max_results'     => $maxResults,
            'include_domains' => $includeDomains ?: null,
            'search_depth'    => $depth,
            'include_content' => $includeContent ?: null,
            'budget_seconds'  => $this->client->budgetSecondsOrNull(),
        ], fn ($v) => $v !== null));
    }

    /**
     * Fetch one page's readable text.
     *
     * ⚠ IT RETURNS TEXT, NEVER HTML, and that is a security decision as much as
     * a token one: the body came from the open internet and its next stops are
     * a model prompt and a user's screen. The service strips it at the
     * boundary so no product has to remember to - and a product that forgets is
     * the likelier failure.
     *
     * ⚠ NULL MEANS "NOT USABLE", AND DELIBERATELY DOES NOT SAY WHY. Unsafe,
     * unreachable, 404 and "no readable text" are one answer, because a caller
     * that could tell them apart could use this endpoint to map the estate's
     * internal network from the outside. The real reason is in the service's
     * log, where an operator can read it and nobody else can.
     *
     * @return array{url: string, title: string, text: string, bytes: int}|null
     *
     * @throws AiUnavailableException
     */
    /**
     * Research end to end in ai-service (`POST /v1/research/run`, 1.33): search (or one `url`),
     * read the top pages, drop injection attempts, synthesise from THIS product's template
     * (`template`, synced by `ai:tasks-sync`, its prompt reading `{{payload}}`), and keep only
     * claims that cite a real source.
     *
     * Returns `{status: success|empty|ungrounded|failed, answer, key_points: [{text, sources}],
     * sources: [{title, url, snippet, published_at}], search: {provider, cached, found, fetched,
     * dropped}, error?}`. What to SAY for empty / ungrounded / failed is the product's: it is its
     * voice.
     *
     * @param  array<string, mixed>  $request  `query` or `url`, `task`, `template`, and optionally `search_query`,
     *   `include_domains`, `max_results`, `fetch_top`, `search_depth`, `deadline_seconds`, `correlation`
     * @return array<string, mixed>
     *
     * @throws AiUnavailableException|AllProvidersFailedException
     */
    public function run(array $request): array
    {
        return $this->client->postJson('api/v1/research/run', array_filter($request, fn ($v) => $v !== null && $v !== []));
    }

    public function fetch(string $url, ?int $timeout = null, ?int $maxBytes = null): ?array
    {
        try {
            return $this->client->postJson('api/v1/research/fetch', array_filter([
                'url'       => $url,
                'timeout'   => $timeout,
                'max_bytes' => $maxBytes,
            ], fn ($v) => $v !== null));
        } catch (AllProvidersFailedException) {
            /*
             * A fetch has no waterfall - nobody is paid and there is nothing to
             * fall through - so the only shape the service can return for an
             * unusable page is a 422, which the client maps to this. Letting it
             * propagate would make every blocked URL look like an estate-wide
             * AI outage to a caller that catches the usual four.
             */
            return null;
        }
    }
}
