<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiResearch;
use Shirahcan\AiClient\Exceptions\AiOverBudgetException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Shirahcan\AiClient\FakeAiClient;

/**
 * The research surface: search, fetch, and the failure mapping.
 */
class ResearchTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    private function client(array $responses): AiClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new AiClient(
            baseUrl: 'http://127.0.0.1:8007',
            trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']),
        );
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_a_search_returns_the_services_results(): void
    {
        $client = $this->client([$this->json([
            'results'  => [['url' => 'https://www.canada.ca/x', 'title' => 'x']],
            'provider' => 'tavily', 'latency_ms' => 120, 'attempts' => [],
        ])]);

        $out = $client->research()->search('research.search', 'LMIA window');

        $this->assertSame('tavily', $out['provider']);
        $this->assertSame('https://www.canada.ca/x', $out['results'][0]['url']);
    }

    public function test_the_task_and_query_reach_the_service(): void
    {
        $client = $this->client([$this->json(['results' => [], 'provider' => 'tavily', 'latency_ms' => 1, 'attempts' => []])]);

        $client->research()->search('research.search', 'a question', includeDomains: ['canada.ca'], depth: 'advanced');

        $body = json_decode((string) $this->sent[0]->getBody(), true);

        $this->assertSame('api/v1/research/search', ltrim($this->sent[0]->getUri()->getPath(), '/'));
        $this->assertSame('research.search', $body['task']);
        $this->assertSame(['canada.ca'], $body['include_domains']);
        $this->assertSame('advanced', $body['search_depth']);
    }

    /**
     * ⚠ The trust key goes in a HEADER. In a query string it lands in access
     * logs and Referer headers, and this one opens the estate's provider
     * accounts.
     */
    public function test_the_trust_key_travels_in_a_header(): void
    {
        $client = $this->client([$this->json(['results' => [], 'provider' => 'p', 'latency_ms' => 1, 'attempts' => []])]);

        $client->research()->search('t', 'q');

        $this->assertSame('Bearer k', $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('k', $this->sent[0]->getUri()->getQuery());
    }

    /**
     * Page bodies are opt-in. A caller that only needs to CHOOSE a source
     * should not pay for bodies it will discard.
     */
    public function test_page_bodies_are_not_requested_unless_asked_for(): void
    {
        $client = $this->client([
            $this->json(['results' => [], 'provider' => 'p', 'latency_ms' => 1, 'attempts' => []]),
            $this->json(['results' => [], 'provider' => 'p', 'latency_ms' => 1, 'attempts' => []]),
        ]);

        $client->research()->search('t', 'q');
        $this->assertArrayNotHasKey('include_content', json_decode((string) $this->sent[0]->getBody(), true));

        $client->research()->search('t', 'q', includeContent: true);
        $this->assertTrue(json_decode((string) $this->sent[1]->getBody(), true)['include_content']);
    }

    public function test_a_fetch_returns_the_pages_text(): void
    {
        $client = $this->client([$this->json([
            'url' => 'https://www.canada.ca/x', 'title' => 'x', 'text' => 'body text', 'bytes' => 9,
        ])]);

        $page = $client->research()->fetch('https://www.canada.ca/x');

        $this->assertSame('body text', $page['text']);
    }

    /**
     * ⚠ AN UNFETCHABLE PAGE IS NULL, NOT AN OUTAGE.
     *
     * The service answers 422 `not_fetchable` for a page that is unsafe,
     * unreachable, 404 or simply empty - a fact about ONE URL. If that mapped
     * to AiUnavailableException, every caller's "the AI service is down" branch
     * would fire over a single bad link.
     */
    public function test_an_unfetchable_page_is_null_rather_than_an_outage(): void
    {
        $client = $this->client([$this->json([
            'error' => 'not_fetchable', 'message' => 'nope',
        ], 422)]);

        $this->assertNull($client->research()->fetch('http://10.0.0.1/'));
    }

    /**
     * ⚠ AND A GENUINE OUTAGE IS STILL AN OUTAGE. If `fetch()` swallowed
     * everything, an unreachable service would look like a page that happened
     * not to have any text, and nobody would ever be told the box was down.
     */
    public function test_an_unreachable_service_still_raises_from_fetch(): void
    {
        $mock = new MockHandler([new \GuzzleHttp\Exception\ConnectException(
            'connection refused', new Request('POST', 'api/v1/research/fetch')
        )]);

        $client = new AiClient(
            baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => HandlerStack::create($mock), 'base_uri' => 'http://127.0.0.1:8007/']),
        );

        $this->expectException(AiUnavailableException::class);

        $client->research()->fetch('https://www.canada.ca/x');
    }

    /** No search credential is reported as a failure, not as "unreachable". */
    public function test_a_missing_search_credential_is_a_typed_failure(): void
    {
        $client = $this->client([$this->json([
            'error' => 'no_search_credential', 'message' => 'add one',
        ], 503)]);

        $this->expectException(AllProvidersFailedException::class);

        $client->research()->search('t', 'q');
    }

    /** Over budget stays its own thing here too: opposite remedy. */
    public function test_over_budget_is_not_folded_into_an_outage(): void
    {
        $client = $this->client([$this->json([
            'error' => 'over_budget', 'message' => 'capped',
        ], 429)]);

        $this->expectException(AiOverBudgetException::class);

        $client->research()->search('t', 'q');
    }

    public function test_every_search_credential_failing_carries_the_trail(): void
    {
        $client = $this->client([$this->json([
            'error' => 'all_providers_failed', 'message' => 'refused',
            'attempts' => ['tavily#1' => 'usage limit exceeded'],
        ], 502)]);

        try {
            $client->research()->search('t', 'q');
            $this->fail('expected AllProvidersFailedException');
        } catch (AllProvidersFailedException $e) {
            $this->assertSame(['tavily#1' => 'usage limit exceeded'], $e->attempts);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The fake is part of the contract
    |--------------------------------------------------------------------------
    */

    public function test_the_fake_research_surface_is_a_real_subclass(): void
    {
        $this->assertInstanceOf(AiResearch::class, (new FakeAiClient())->research());
    }

    public function test_the_fake_returns_queued_results_and_records_the_query(): void
    {
        $fake = (new FakeAiClient())->queueSearch([['url' => 'https://ok.example', 'title' => 'ok']]);

        $out = $fake->research()->search('research.search', 'my question');

        $this->assertSame('https://ok.example', $out['results'][0]['url']);
        $this->assertSame([['task' => 'research.search', 'query' => 'my question']], $fake->searches);
    }

    /**
     * ⚠ AN EXHAUSTED FAKE THROWS. A fake that invented an empty result set
     * would let a test pass while asserting nothing, which is worse than no
     * test.
     */
    public function test_the_fake_throws_when_nothing_was_queued(): void
    {
        $this->expectException(AiUnavailableException::class);

        (new FakeAiClient())->research()->search('t', 'q');
    }

    /**
     * An unregistered URL fetches to null, so a test that forgot to register
     * one exercises the REFUSAL path rather than receiving invented page text.
     */
    public function test_an_unregistered_page_fetches_to_null_in_the_fake(): void
    {
        $this->assertNull((new FakeAiClient())->research()->fetch('https://nobody.registered.this'));
    }

    public function test_the_fake_serves_a_registered_page(): void
    {
        $fake = (new FakeAiClient())->queuePage('https://ok.example', [
            'url' => 'https://ok.example', 'title' => 't', 'text' => 'the text', 'bytes' => 8,
        ]);

        $this->assertSame('the text', $fake->research()->fetch('https://ok.example')['text']);
    }

    /**
     * ⚠ THE GAP THIS CLOSES. `usageReport()`, `myUsageReport()` and
     * `modelStatus()` were not overridden on the fake, so they fell through to
     * the real client and made LIVE HTTP CALLS from inside a test. Portify's
     * ConsoleUsageTest asserted an admin console reported 3 AI calls and was
     * handed 2,398 by a reachable ai-service.
     */
    public function test_the_fake_serves_a_usage_report_without_touching_the_network(): void
    {
        $fake = (new FakeAiClient())->queueUsageReport([
            'from' => '2026-10-01T00:00:00+00:00', 'to' => '2026-10-12T00:00:00+00:00',
            'totals' => ['calls' => 42, 'tokens_in' => 10, 'tokens_out' => 5, 'spend_micros' => 7],
            'by_outcome' => ['ok' => 40, 'failed' => 2],
        ]);

        $report = $fake->myUsageReport();

        $this->assertSame(42, $report->calls);
        $this->assertSame(2, $report->failedCalls());
    }

    /** With nothing queued it reports ZERO, honestly, so a test fails loudly. */
    public function test_an_unqueued_usage_report_is_empty_not_invented(): void
    {
        $this->assertSame(0, (new FakeAiClient())->usageReport()->calls);
    }

    public function test_the_fake_serves_model_status_without_touching_the_network(): void
    {
        $fake = (new FakeAiClient())->queueModelStatus([
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'usable' => true],
        ]);

        $this->assertSame(1, $fake->modelStatus()->total);
    }
}
