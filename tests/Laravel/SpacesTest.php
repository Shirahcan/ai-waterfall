<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\AiSpaces;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Spaces\DefaultSpaceOwner;
use Shirahcan\AiClient\Laravel\Spaces\SpaceKey;
use Shirahcan\AiClient\Laravel\Spaces\SpaceOwner;
use Shirahcan\AiClient\Laravel\Spaces\Spaces;

/**
 * Assistant spaces in the kit (1.57, plan S2a): the HTTP shapes ai-service expects, a space resolved
 * ONCE per request from the session, and the fake keeping a space's threads to itself.
 */
class SpacesTest extends TestCase
{
    /** @var list<PsrRequest> */
    private array $sent = [];

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-client.base_url', 'http://127.0.0.1:8007');
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('ai-client.assistant.key', 'porter');
    }

    /** @param list<Response> $responses */
    private function http(array $responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $handler) => function (PsrRequest $request, array $options) use ($handler) {
            $this->sent[] = $request;

            return $handler($request, $options);
        });

        return new AiClient(baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    /** @param array<string, mixed> $body */
    private function reply(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function requestAs(?string $userId): Request
    {
        $request = Request::create('/v1/assistant/threads');
        $request->setUserResolver(fn () => $userId === null ? null : new GenericUser(['id' => $userId]));

        return $request;
    }

    public function test_resolve_and_the_space_routes_use_the_paths_and_bodies_ai_service_expects(): void
    {
        $ai = $this->http([
            $this->reply(['space' => ['id' => 'sp-1', 'assistant' => 'porter', 'owner_kind' => 'user', 'audience' => 'consultant', 'created' => true]], 201),
            $this->reply(['conversations' => [], 'has_more' => false, 'next_before' => null]),
            $this->reply(['memory' => ['key' => 'pref.tone', 'value' => 'brief', 'source' => 'explicit']]),
            $this->reply(['history' => ['Newest', 'Older']]),
            $this->reply(['state' => ['active_conversation_id' => null]]),
        ]);

        $space = $ai->spaces()->resolve('porter', 'user', 'u-1', 'consultant');
        $ai->spaces()->conversations('sp-1', ['limit' => 2, 'pinned' => true]);
        $ai->spaces()->remember('sp-1', 'pref.tone', 'brief');
        $history = $ai->spaces()->inputHistory('sp-1', 10);
        $ai->spaces()->reopenNext('sp-1', null);

        $this->assertSame('sp-1', $space['id']);
        $this->assertSame(['PUT', '/api/v1/spaces/resolve'], [$this->sent[0]->getMethod(), $this->sent[0]->getUri()->getPath()]);
        $this->assertSame(['assistant' => 'porter', 'owner_kind' => 'user', 'owner_ref' => 'u-1', 'audience' => 'consultant'],
            json_decode((string) $this->sent[0]->getBody(), true));
        $this->assertSame('/api/v1/spaces/sp-1/conversations', $this->sent[1]->getUri()->getPath());
        $this->assertSame('limit=2&pinned=1', $this->sent[1]->getUri()->getQuery());
        $this->assertSame(['PUT', '/api/v1/spaces/sp-1/memory/pref.tone'], [$this->sent[2]->getMethod(), $this->sent[2]->getUri()->getPath()]);
        $this->assertSame(['Newest', 'Older'], $history);
        $this->assertSame(['PATCH', '/api/v1/spaces/sp-1/state'], [$this->sent[4]->getMethod(), $this->sent[4]->getUri()->getPath()]);
        $this->assertSame('space:sp-1', AiSpaces::memoryRef('sp-1'));
    }

    public function test_a_refusal_carries_the_services_status_and_sentence(): void
    {
        $ai = $this->http([$this->reply(['error' => 'no_such_conversation', 'message' => 'This space holds no conversation with that id.'], 404)]);

        try {
            $ai->spaces()->conversation('sp-1', 'not-mine');
            $this->fail('A 404 from the service must throw.');
        } catch (AiRequestRefusedException $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('no_such_conversation', $e->errorCode);
        }
    }

    public function test_the_space_is_resolved_once_per_request_from_the_session(): void
    {
        $fake = new FakeAiClient;
        $this->app->instance(AiClient::class, $fake);
        $spaces = $this->app->make(Spaces::class);
        $request = $this->requestAs('u-7');

        $first = $spaces->current($request);
        $second = $spaces->current($request);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertCount(1, array_filter($fake->spaces()->calls, fn ($c) => $c[0] === 'resolve'));
        $this->assertSame(['porter', 'user', 'u-7', null, null], $fake->spaces()->calls[0][1]);
    }

    public function test_no_person_means_no_space_and_no_call(): void
    {
        $fake = new FakeAiClient;
        $this->app->instance(AiClient::class, $fake);

        $this->assertNull($this->app->make(Spaces::class)->current($this->requestAs(null)));
        $this->assertSame([], $fake->spaces()->calls);
        $this->assertInstanceOf(DefaultSpaceOwner::class, $this->app->make(SpaceOwner::class));
    }

    public function test_a_product_that_names_no_assistant_is_told_how_to_fix_it(): void
    {
        $this->app['config']->set('ai-client.assistant.key', null);
        $this->app->instance(AiClient::class, new FakeAiClient);

        $this->expectExceptionMessage('set AI_ASSISTANT');
        $this->app->make(Spaces::class)->current($this->requestAs('u-1'));
    }

    public function test_a_guest_is_known_by_a_hash_of_their_token_never_the_token(): void
    {
        $key = SpaceKey::guest('browser-token');

        $this->assertSame('guest', $key->kind);
        $this->assertSame('guest:'.hash('sha256', 'browser-token'), $key->ref);
        $this->assertStringNotContainsString('browser-token', $key->ref);
    }

    /** ⚠ The fake keeps the service's rule a product's tests rely on: another space's thread is a 404. */
    public function test_the_fake_keeps_each_spaces_threads_to_itself(): void
    {
        $fake = (new FakeAiClient)->spaces();
        $mine = $fake->resolve('porter', 'user', 'u-1', 'consultant')['id'];
        $theirs = $fake->resolve('porter', 'user', 'u-2', 'consultant')['id'];
        $thread = $fake->start($theirs, ['title' => 'Theirs'])['conversation']['id'];

        $this->expectException(AiRequestRefusedException::class);
        $fake->conversation($mine, $thread);
    }
}
