<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiPersonas;
use Shirahcan\AiClient\FakeAiClient;

/**
 * The stored voice: named by the caller, owned by the service.
 */
class PersonaTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    /** @param array<int, Response> $responses */
    private function client(array $responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
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

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function generation(): Response
    {
        return $this->json([
            'result' => ['ok' => true], 'provider' => 'gemini', 'model' => 'm',
            'latency_ms' => 1, 'tokens' => ['in' => 1, 'out' => 1], 'attempts' => [],
        ]);
    }

    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    public function test_the_persona_and_audience_reach_the_service(): void
    {
        $this->client([$this->generation()])
            ->withPersona('porter', 'consultant')
            ->generateJson('chat.answer', 's', 'p');

        $this->assertSame('porter', $this->body()['persona']);
        $this->assertSame('consultant', $this->body()['audience']);
    }

    /** A call with no persona names none, so an extraction stays unstyled. */
    public function test_no_persona_sends_nothing(): void
    {
        $this->client([$this->generation()])->generateJson('extract.document', 's', 'p');

        $this->assertArrayNotHasKey('persona', $this->body());
        $this->assertArrayNotHasKey('audience', $this->body());
    }

    /**
     * ⚠ THE CLONE AGAIN. The client is a singleton, so a persona that mutated
     * in place would put one audience's voice into the next request handled by
     * the same worker - a consultant's answer in a client's chat.
     */
    public function test_it_does_not_leak_onto_the_shared_instance(): void
    {
        $client = $this->client([$this->generation(), $this->generation()]);

        $client->withPersona('porter', 'consultant')->generateJson('t', 's', 'p');
        $client->generateJson('t', 's', 'p');

        $this->assertSame('consultant', $this->body(0)['audience']);
        $this->assertArrayNotHasKey('persona', $this->body(1));
    }

    public function test_it_composes_with_correlation_and_budget(): void
    {
        $this->client([$this->generation()])
            ->withPersona('porter', 'client')
            ->withCorrelation(['_key' => 'case:1'])
            ->withBudget(6.0)
            ->generateJson('t', 's', 'p');

        $body = $this->body();

        $this->assertSame('porter', $body['persona']);
        $this->assertSame(['_key' => 'case:1'], $body['correlation']);
        $this->assertEquals(6.0, $body['budget_seconds']);
    }

    public function test_publishing_a_voice_posts_it(): void
    {
        $this->client([$this->json(['persona' => ['id' => 1, 'version' => 1]])])
            ->personas()->publish('porter', 'Porter', 'BE WARM.', 'consultant', 'admin-1');

        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertSame('api/v1/personas', ltrim($this->sent[0]->getUri()->getPath(), '/'));
        $this->assertSame('BE WARM.', $this->body()['voice']);
        $this->assertSame('consultant', $this->body()['audience']);
    }

    /**
     * ⚠ THERE IS NO `$product` ARGUMENT, AND THAT IS DELIBERATE. The service
     * derives the product from the trust key, so a product parameter here would
     * be a lie - and a convincing one, since it would look as though a caller
     * could manage another product's voice.
     */
    public function test_the_api_offers_no_way_to_name_another_product(): void
    {
        $names = array_map(
            fn (ReflectionParameter $p) => $p->getName(),
            (new ReflectionMethod(AiPersonas::class, 'publish'))->getParameters(),
        );

        $this->assertNotContains('product', $names);
    }

    public function test_a_rollback_posts_to_activate(): void
    {
        $this->client([$this->json(['persona' => ['id' => 3, 'version' => 3]])])
            ->personas()->rollBackTo(1, 'admin-1');

        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertStringContainsString('api/v1/personas/1/activate', (string) $this->sent[0]->getUri());
    }

    public function test_deactivating_deletes(): void
    {
        $ok = $this->client([$this->json(['deactivated' => true])])->personas()->deactivate(7);

        $this->assertTrue($ok);
        $this->assertSame('DELETE', $this->sent[0]->getMethod());
    }

    /*
    |--------------------------------------------------------------------------
    | The fake
    |--------------------------------------------------------------------------
    */

    public function test_the_fake_persona_surface_is_a_real_subclass(): void
    {
        $this->assertInstanceOf(AiPersonas::class, (new FakeAiClient())->personas());
    }

    public function test_the_fake_records_the_voice_a_call_asked_for(): void
    {
        $fake = (new FakeAiClient())->queue(['ok' => true]);

        $fake->withPersona('porter', 'consultant')->generateJson('t', 's', 'p');

        $this->assertSame(['persona' => 'porter', 'audience' => 'consultant'], $fake->persona());
    }

    /**
     * ⚠ THE FAKE VERSIONS TOO, because versioning is the part a product is most
     * likely to get wrong: a screen that assumes publish() edits in place would
     * show the wrong history, and a fake that returned version 1 every time
     * would let that ship.
     */
    public function test_the_fake_versions_a_republished_voice(): void
    {
        $personas = (new FakeAiClient())->personas();

        $this->assertSame(1, $personas->publish('porter', 'Porter', 'first')['version']);
        $this->assertSame(2, $personas->publish('porter', 'Porter', 'second')['version']);
    }

    /** A different audience is a different scope, so its versions start at 1. */
    public function test_the_fake_versions_per_audience(): void
    {
        $personas = (new FakeAiClient())->personas();

        $personas->publish('porter', 'Porter', 'shared');

        $this->assertSame(1, $personas->publish('porter', 'Porter', 'for consultants', 'consultant')['version']);
    }

    public function test_the_fake_serves_queued_personas(): void
    {
        $fake = (new FakeAiClient())->queuePersonas([
            ['id' => 1, 'key' => 'porter', 'audience' => null, 'label' => 'Porter',
             'voice' => 'v', 'version' => 1, 'is_active' => true],
        ]);

        $this->assertCount(1, $fake->personas()->all());
    }
}
