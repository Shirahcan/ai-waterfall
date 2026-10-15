<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiModelStatus;
use Shirahcan\AiClient\AiClient;

/**
 * Model state is READ from the service, never worked out by a product.
 *
 * ⚠ THE DEFECT THIS PREVENTS IS ON THE RECORD. A rung the waterfall was
 * silently skipping read as perfectly healthy on every surface, and the only
 * symptom anyone could see was latency - which is how github_models sat at 0
 * successes in 81 calls, still being offered on every single request.
 */
class ModelStatusTest extends TestCase
{
    private function client(Response ...$responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new AiClient('http://127.0.0.1:8007', 'test-key', 60,
            new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    private function payload(array $overrides = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_merge([
            'credentials' => [
                ['provider' => 'groq', 'model' => 'gpt-oss-120b', 'state' => 'available'],
                ['provider' => 'gemini', 'model' => 'flash-lite', 'state' => 'benched',
                    'cooldown' => ['seconds_remaining' => 67300]],
                ['provider' => 'github_models', 'model' => 'gpt-4o-mini', 'state' => 'circuit_open',
                    'circuit' => ['open' => true, 'seconds_remaining' => 1500]],
                ['provider' => 'groq', 'model' => 'gpt-oss-120b', 'state' => 'size_limited',
                    'context_limit_chars' => 37291],
            ],
            'summary' => [
                'total' => 4, 'usable' => 1, 'benched' => 1,
                'circuit_open' => 1, 'size_limited' => 1, 'disabled' => 0,
            ],
        ], $overrides)));
    }

    public function test_it_carries_the_services_counts(): void
    {
        $status = $this->client($this->payload())->modelStatus(cacheSeconds: null);

        $this->assertSame(4, $status->total);
        $this->assertSame(1, $status->usable);
        $this->assertSame(1, $status->benched);
        $this->assertSame(1, $status->circuitOpen);
        $this->assertSame(1, $status->sizeLimited);
    }

    /**
     * ⚠ BENCHING IS THE SYSTEM WORKING, NOT AN OUTAGE. A quota-exhausted key
     * SHOULD leave the rotation. The only state worth alarming on is having
     * nothing left to fall through to - alarming on "something is benched"
     * would page someone every single day for correct behaviour.
     */
    public function test_health_is_about_having_something_left_not_about_benches(): void
    {
        $this->assertTrue($this->client($this->payload())->modelStatus(cacheSeconds: null)->isHealthy());

        $none = $this->payload(['summary' => [
            'total' => 4, 'usable' => 0, 'benched' => 3,
            'circuit_open' => 1, 'size_limited' => 0, 'disabled' => 0,
        ]]);

        $this->assertFalse($this->client($none)->modelStatus(cacheSeconds: null)->isHealthy());
    }

    /**
     * ⚠ A SIZE-LIMITED MODEL IS STILL USABLE. It answers shorter prompts, so
     * listing it as unavailable overstates the problem and would send an
     * operator hunting for an outage that is not there.
     */
    public function test_a_size_limited_model_counts_as_available(): void
    {
        $status = $this->client($this->payload())->modelStatus(cacheSeconds: null);

        $this->assertCount(2, $status->available());
        $this->assertCount(2, $status->unavailable());

        $states = array_map(static fn ($m) => $m['state'], $status->unavailable());
        sort($states);
        $this->assertSame(['benched', 'circuit_open'], $states);
    }

    public function test_the_summary_names_why_models_are_missing(): void
    {
        $summary = $this->client($this->payload())->modelStatus(cacheSeconds: null)->summary();

        $this->assertStringContainsString('1 of 4 models ready', $summary);
        $this->assertStringContainsString('rate limited', $summary);
        $this->assertStringContainsString('failing', $summary);
    }

    /**
     * ⚠ AN OLDER SERVICE MUST NOT READ AS ZERO MODELS. A box that has not
     * deployed the summary yet still sends the credential rows, and a product
     * showing "0 of 0 ready" would look like a total outage during a rollout.
     */
    public function test_it_counts_the_rows_when_an_older_service_sends_no_summary(): void
    {
        $payload = json_decode((string) $this->payload()->getBody(), true);
        unset($payload['summary']);

        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode($payload));
        $status = $this->client($response)->modelStatus(cacheSeconds: null);

        $this->assertSame(4, $status->total);
        $this->assertSame(1, $status->usable);
        $this->assertSame(1, $status->circuitOpen);
        $this->assertTrue($status->isHealthy());
    }

    /** No models configured is not "healthy with zero"; it is nothing to use. */
    public function test_an_empty_estate_is_not_healthy(): void
    {
        $empty = new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['credentials' => [], 'summary' => ['total' => 0, 'usable' => 0]]));

        $status = $this->client($empty)->modelStatus(cacheSeconds: null);

        $this->assertFalse($status->isHealthy());
        $this->assertStringContainsString('No AI models', $status->summary());
    }

    /** A cache fault costs speed, never the answer. */
    public function test_a_ttl_still_reports_when_the_cache_is_unavailable(): void
    {
        $status = $this->client($this->payload())->modelStatus(cacheSeconds: 30);

        $this->assertInstanceOf(AiModelStatus::class, $status);
        $this->assertSame(4, $status->total);
    }

    // --------------------------------------- the ledger verdict, F13

    private function withHealth(array $verdicts, array $summary = []): Response
    {
        $credentials = [];
        $i = 0;

        foreach ($verdicts as $provider => $verdict) {
            $credentials[] = [
                'provider' => $provider,
                'model'    => $provider.'-model',
                'state'    => 'available',
                'health'   => ['verdict' => $verdict, 'calls' => 100, 'window_hours' => 24],
            ];
            $i++;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'credentials' => $credentials,
            'summary'     => array_merge(['total' => $i, 'usable' => $i], $summary),
        ]));
    }

    /**
     * ⚠ THE F13 SHAPE. Every rung in rotation, two of three answering
     * nothing. `isHealthy` is TRUE and correct; the estate still has no
     * redundancy, and only the second question finds it.
     */
    public function test_every_rung_can_be_in_rotation_while_only_one_answers(): void
    {
        $status = $this->client($this->withHealth([
            'groq'    => 'healthy',
            'gemini'  => 'exhausted',
            'mistral' => 'exhausted',
        ]))->modelStatus(null);

        $this->assertTrue($status->isHealthy(), 'nothing is out of rotation, so this must stay true');
        $this->assertFalse($status->hasRedundancy());
        $this->assertSame(1, $status->contributing);
        $this->assertSame(2, $status->exhausted);

        // And it says so in the one line a dashboard tile shows.
        $this->assertStringContainsString('no redundancy left', $status->summary());
    }

    public function test_a_degraded_rung_still_counts_as_contributing(): void
    {
        $status = $this->client($this->withHealth([
            'groq'   => 'healthy',
            'gemini' => 'degraded',
        ]))->modelStatus(null);

        $this->assertTrue($status->hasRedundancy());
        $this->assertSame(2, $status->contributing);
        $this->assertStringNotContainsString('redundancy', $status->summary());
    }

    public function test_it_lists_the_rungs_that_have_stopped_contributing(): void
    {
        $status = $this->client($this->withHealth([
            'groq'    => 'healthy',
            'gemini'  => 'exhausted',
            'ollama'  => 'broken',
        ]))->modelStatus(null);

        $stopped = $status->notContributing();

        $this->assertCount(2, $stopped);
        $this->assertSame(['gemini', 'ollama'], array_column($stopped, 'provider'));

        // Distinct from `unavailable()`, which is about rotation this minute -
        // and here that set is EMPTY, which is exactly the problem.
        $this->assertSame([], $status->unavailable());
    }

    /**
     * ⚠ UNKNOWN IS NOT FINE. A service that has not deployed the detector
     * must not read as "nothing is contributing", which is the alarm state - a
     * false alarm is how people learn to ignore the real one.
     */
    public function test_a_service_that_does_not_report_tier_health_says_unknown_not_zero(): void
    {
        $status = $this->client($this->payload())->modelStatus(null);

        $this->assertFalse($status->reportsTierHealth());
        $this->assertNull($status->contributing);
        $this->assertNull($status->hasRedundancy());
        // And the summary stays exactly what it was before the field existed.
        $this->assertStringNotContainsString('redundancy', $status->summary());
    }

    public function test_nothing_answering_at_all_is_stated_differently_from_one_rung(): void
    {
        $status = $this->client($this->withHealth([
            'gemini'  => 'exhausted',
            'mistral' => 'exhausted',
        ]))->modelStatus(null);

        $this->assertSame(0, $status->contributing);
        $this->assertFalse($status->hasRedundancy());
        $this->assertStringContainsString('No rung has answered', $status->summary());
    }

    public function test_it_counts_verdicts_from_the_rows_when_the_summary_omits_them(): void
    {
        $status = $this->client($this->withHealth(
            ['groq' => 'healthy', 'gemini' => 'exhausted'],
            // An older service: a summary with the rotation counts and no health.
            ['benched' => 0, 'circuit_open' => 0, 'size_limited' => 0, 'disabled' => 0],
        ))->modelStatus(null);

        $this->assertSame(1, $status->contributing);
        $this->assertSame(1, $status->exhausted);
    }

    /** Silence earns nothing here either. */
    public function test_an_unjudged_rung_is_neither_contributing_nor_exhausted(): void
    {
        $status = $this->client($this->withHealth([
            'groq'      => 'healthy',
            'anthropic' => 'unjudged',
        ]))->modelStatus(null);

        $this->assertSame(1, $status->contributing);
        $this->assertSame(0, $status->exhausted);
        $this->assertSame(1, $status->unjudged);
        $this->assertFalse($status->hasRedundancy());
    }
}
