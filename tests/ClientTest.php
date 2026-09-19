<?php

namespace Shirahcan\AiWaterfall\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiWaterfall\AiWaterfallClient;
use Shirahcan\AiWaterfall\Exceptions\AiOverBudgetException;
use Shirahcan\AiWaterfall\Exceptions\AiUnavailableException;
use Shirahcan\AiWaterfall\Exceptions\AllProvidersFailedException;
use Shirahcan\AiWaterfall\Exceptions\NoCompliantCredentialException;
use Shirahcan\AiWaterfall\FakeAiWaterfall;

class ClientTest extends TestCase
{
    private function client(Response ...$responses): AiWaterfallClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new AiWaterfallClient('http://127.0.0.1:8007', 'test-key', 60,
            new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_a_successful_call_returns_the_result_and_the_trail(): void
    {
        $r = $this->client($this->json(200, [
            'result' => ['ok' => true], 'provider' => 'groq', 'model' => 'm',
            'latency_ms' => 120, 'tokens' => ['in' => 10, 'out' => 5],
            'attempts' => [['provider' => 'gemini'], ['provider' => 'groq']],
        ]))->generateJson('t', 's', 'p');

        $this->assertSame(['ok' => true], $r->array());
        $this->assertSame('groq', $r->provider);
        $this->assertTrue($r->fellThrough(), 'the attempt trail was lost, so a degraded waterfall looks healthy');
    }

    /** ⚠ over_budget must be its own exception: opposite remedy to an outage. */
    public function test_over_budget_is_its_own_exception(): void
    {
        $this->expectException(AiOverBudgetException::class);
        $this->client($this->json(429, ['error' => 'over_budget', 'message' => 'capped']))
            ->generateJson('t', 's', 'p');
    }

    /** ⚠ A compliance refusal is not an outage, and must not be retried. */
    public function test_a_compliance_refusal_is_its_own_exception(): void
    {
        $this->expectException(NoCompliantCredentialException::class);
        $this->client($this->json(503, ['error' => 'no_compliant_credential']))
            ->generateJson('t', 's', 'p');
    }

    /** The familiar shape, kept familiar so migrating adds no new failure mode. */
    public function test_all_providers_failed_carries_the_attempts(): void
    {
        try {
            $this->client($this->json(502, [
                'error' => 'all_providers_failed', 'attempts' => ['gemini' => '429'],
            ]))->generateJson('t', 's', 'p');
            $this->fail('expected AllProvidersFailedException');
        } catch (AllProvidersFailedException $e) {
            $this->assertSame(['gemini' => '429'], $e->attempts);
        }
    }

    /** ⚠ The service being down looks like today's failure, not a new one. */
    public function test_an_unreachable_service_raises_a_handled_shape(): void
    {
        $this->expectException(AiUnavailableException::class);
        $this->client(new Response(500, [], 'not json'))->generateJson('t', 's', 'p');
    }

    // ------------------------------------------------------------------ fake

    public function test_the_fake_serves_queued_results_without_a_service(): void
    {
        $fake = (new FakeAiWaterfall())->queue(['action' => 'ask']);

        $this->assertSame(['action' => 'ask'], $fake->generateJson('t', 's', 'p')->array());
        $this->assertSame('t', $fake->calls[0]['task']);
    }

    /** ⚠ An exhausted queue throws rather than inventing a passing result. */
    public function test_the_fake_throws_when_its_queue_is_empty(): void
    {
        $this->expectException(AiUnavailableException::class);
        (new FakeAiWaterfall())->generateJson('t', 's', 'p');
    }
}
