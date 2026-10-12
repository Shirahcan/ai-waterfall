<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;

/**
 * The caller's own identifiers travel with every call.
 *
 * ⚠ WHY IT EXISTS. ai-service's usage table could not say WHOSE question a row
 * was, so Portify kept a second ledger (11,853 rows against the service's
 * 3,547) with its own cost calculator and its own copy of the rate table,
 * purely to answer "what did this case cost to run".
 */
class CorrelationTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    private function client(int $responses = 1): AiClient
    {
        $queue = [];

        for ($i = 0; $i < $responses; $i++) {
            $queue[] = new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'result' => ['ok' => true], 'provider' => 'gemini', 'model' => 'm',
                'latency_ms' => 1, 'tokens' => ['in' => 1, 'out' => 1], 'attempts' => [],
            ]));
        }

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new AiClient(
            baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']),
        );
    }

    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    public function test_the_bag_reaches_the_service(): void
    {
        $this->client()
            ->withCorrelation(['_key' => 'case:9e3f', 'user_id' => 'u-1'])
            ->generateJson('chat.answer', 's', 'p');

        $this->assertSame(['_key' => 'case:9e3f', 'user_id' => 'u-1'], $this->body()['correlation']);
    }

    /**
     * ⚠ THE CLONE IS THE WHOLE SAFETY PROPERTY. The client is registered as a
     * SINGLETON, so mutating in place would leak one user's correlation onto
     * the next request handled by the same worker - which is worse than no
     * correlation at all, because the row would be confidently attributed to
     * the wrong person.
     */
    public function test_it_does_not_leak_onto_the_shared_instance(): void
    {
        $client = $this->client(2);

        $client->withCorrelation(['_key' => 'case:aaa'])->generateJson('t', 's', 'p');
        $client->generateJson('t', 's', 'p');

        $this->assertSame(['_key' => 'case:aaa'], $this->body(0)['correlation']);
        $this->assertArrayNotHasKey('correlation', $this->body(1),
            'the correlation leaked onto the next call through the shared client');
    }

    /** It composes with withBudget, in either order, without losing either. */
    public function test_it_composes_with_withBudget(): void
    {
        $this->client()
            ->withBudget(6.0, 3)
            ->withCorrelation(['_key' => 'case:1'])
            ->generateJson('t', 's', 'p');

        $body = $this->body();

        $this->assertSame(['_key' => 'case:1'], $body['correlation']);
        $this->assertEquals(6.0, $body['budget_seconds']);
        $this->assertSame(3, $body['per_provider_timeout']);
    }

    public function test_the_reverse_order_also_keeps_both(): void
    {
        $this->client()
            ->withCorrelation(['_key' => 'case:1'])
            ->withBudget(6.0)
            ->generateJson('t', 's', 'p');

        $this->assertSame(['_key' => 'case:1'], $this->body()['correlation']);
        $this->assertEquals(6.0, $this->body()['budget_seconds']);
    }

    /** An empty bag is no bag: the key is simply absent from the wire. */
    public function test_an_empty_bag_sends_nothing(): void
    {
        $this->client()->withCorrelation([])->generateJson('t', 's', 'p');

        $this->assertArrayNotHasKey('correlation', $this->body());
    }

    public function test_no_correlation_sends_nothing(): void
    {
        $this->client()->generateJson('t', 's', 'p');

        $this->assertArrayNotHasKey('correlation', $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | The fake must not forget a call it was clearly asked to make
    |--------------------------------------------------------------------------
    */

    /**
     * ⚠ THE DEFECT THIS GUARDS, AND WHY ITS DIRECTION MATTERS. withBudget()
     * and withCorrelation() return a CLONE - they have to, because the client
     * is a singleton. A clone copies arrays by value, so every call recorded
     * after a with*() landed on the clone and was invisible from the instance
     * the test was holding.
     *
     * It fails as a FALSE NEGATIVE, which is the worst direction: a test
     * asserting "the AI was called with X" sees an empty log and reports that
     * working code never called the AI, and a test asserting "the AI was NOT
     * called" passes unconditionally.
     */
    public function test_the_fake_remembers_a_call_made_through_a_clone(): void
    {
        $fake = new \Shirahcan\AiClient\FakeAiClient();
        $fake->queue(['ok' => true]);

        // The code under test holds the clone; the test holds the original.
        $fake->withBudget(6.0)->withCorrelation(['_key' => 'case:1'])
            ->generateJson('chat.answer', 's', 'p');

        $this->assertCount(1, $fake->log->calls,
            'the fake forgot a call made through a clone - every assertion about it is a false negative');
        $this->assertSame('chat.answer', $fake->log->calls[0]['task']);
        $this->assertSame(['_key' => 'case:1'], $fake->correlation());
    }

    /** The legacy  accessor reads the SHARED log, not a stale copy. */
    public function test_the_legacy_calls_accessor_reads_the_shared_log(): void
    {
        $fake = new \Shirahcan\AiClient\FakeAiClient();
        $fake->queue(['ok' => true]);

        $fake->withBudget(6.0)->generateJson('t', 's', 'p');

        $this->assertCount(1, $fake->calls);
    }

    /**
     * It rides the EMBED body too, because embeddings are billed work and an
     * unattributed cost is the gap this change exists to close.
     */
    public function test_it_rides_the_embed_call(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'vector' => [0.1], 'model' => 'm', 'provider' => 'gemini',
            ])),
        ]));
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        (new AiClient(
            baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']),
        ))->withCorrelation(['_key' => 'case:1'])->embed('knowledge.embed.query', 'text');

        $this->assertSame(['_key' => 'case:1'], $this->body()['correlation']);
    }
}
