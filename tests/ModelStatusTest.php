<?php

namespace Shirahcan\AiWaterfall\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiWaterfall\AiModelStatus;
use Shirahcan\AiWaterfall\AiWaterfallClient;

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
    private function client(Response ...$responses): AiWaterfallClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new AiWaterfallClient('http://127.0.0.1:8007', 'test-key', 60,
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
}
