<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Tools\ChatChannelGuard;
use Shirahcan\AiClient\Laravel\Tools\ToolActor;
use Shirahcan\AiClient\Laravel\Tools\ToolCatalog;
use Shirahcan\AiClient\Laravel\Tools\ToolRoutes;
use Shirahcan\AiClient\Laravel\Tools\ToolToken;

class KitCatalog implements ToolCatalog
{
    /** @var list<array{name: string, actor: ToolActor}> */
    public array $ran = [];

    public function definitions(string $audience): array
    {
        return [['name' => 'count_things', 'description' => 'for '.$audience, 'input_schema' => ['type' => 'object']]];
    }

    public function run(string $name, array $input, ToolActor $actor): array
    {
        $this->ran[] = ['name' => $name, 'actor' => $actor];
        if ($name === 'explode') {
            throw new \RuntimeException('secret internals');
        }

        return ['ok' => true, 'result' => ['user' => $actor->userId, 'snapshot' => $actor->extra('sk')]];
    }

    public function actorExists(ToolActor $actor): bool
    {
        return $actor->userId !== 'deleted';
    }
}

class KitGuard implements ChatChannelGuard
{
    public bool $on = true;

    public function enabled(): bool
    {
        return $this->on;
    }

    public function channelFor(Request $request, string $conversationId): ?string
    {
        return $conversationId === 'mine' ? 'conv-mine' : null;
    }
}

/**
 * The tool host a product gets by binding a catalog (phase 2 of the shared assistant plan):
 * signature, token, contract, throttle and the chat events proxy, with no product code.
 */
class ToolHostKitTest extends TestCase
{
    private const SECRET = 'kit-secret-kit-secret-kit-secret-kit-secret';

    private KitCatalog $catalog;

    private KitGuard $guard;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('ai-client.tools.secret', self::SECRET);
    }

    protected function defineRoutes($router): void
    {
        ToolRoutes::host();
        ToolRoutes::chatEvents('assistant/conversations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new KitCatalog();
        $this->guard = new KitGuard();
        $this->app->instance(ToolCatalog::class, $this->catalog);
        $this->app->instance(ChatChannelGuard::class, $this->guard);
        $this->app->instance(AiClient::class, new FakeAiClient());
    }

    /** @return array<string, string> */
    private function signed(string $body, ?string $token = null): array
    {
        $ts = (string) time();

        return array_filter([
            'X-Ai-Timestamp'  => $ts,
            'X-Ai-Signature'  => hash_hmac('sha256', $ts.'.'.$body, self::SECRET),
            'X-Ai-Tool-Token' => $token,
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
        ]);
    }

    public function test_the_manifest_is_signed_and_lists_the_audiences_tools(): void
    {
        $this->getJson('/ai-tools/manifest?audience=admin')->assertStatus(401);

        $this->get('/ai-tools/manifest?audience=partner', $this->signed(''))
            ->assertOk()->assertJsonPath('tools.0.description', 'for partner');
    }

    public function test_a_call_runs_for_the_actor_the_token_names_and_never_leaks_an_exception(): void
    {
        $token = ToolToken::issue(new ToolActor(userId: 'u-1', audience: 'consultant', conversationId: 'c-1', extras: ['sk' => 'snap-1']));

        $this->tool('count_things', $token)->assertOk()->assertJson(['ok' => true, 'result' => ['user' => 'u-1', 'snapshot' => 'snap-1']]);
        $this->assertSame('c-1', $this->catalog->ran[0]['actor']->conversationId);

        $this->tool('explode', $token)->assertStatus(500)->assertJsonPath('ok', false)
            ->assertJsonMissing(['error' => 'secret internals']);
    }

    public function test_a_missing_expired_or_orphaned_token_opens_nothing(): void
    {
        $this->tool('count_things', null)->assertStatus(401);
        $this->tool('count_things', ToolToken::issue(new ToolActor('u-1', 'client'), time() - ToolToken::TTL_SECONDS - 5))->assertStatus(401);
        $this->tool('count_things', ToolToken::issue(new ToolActor('deleted', 'client')))->assertStatus(401);
        $this->tool('count_things', 'not-a-token')->assertStatus(401);
        $this->assertSame([], $this->catalog->ran);
    }

    public function test_a_guest_token_is_accepted_without_a_user(): void
    {
        $guest = ToolActor::guest('visitor-1', '/pricing');
        $this->assertTrue(ToolToken::open(ToolToken::issue($guest))?->isGuest());

        $this->tool('count_things', ToolToken::issue($guest))->assertOk();
    }

    public function test_an_unset_secret_refuses_everything(): void
    {
        config(['ai-client.tools.secret' => '']);

        $this->tool('count_things', ToolToken::issue(new ToolActor('u-1', 'client')))->assertStatus(401);
    }

    public function test_chat_events_relay_the_guarded_channel_and_404_when_off_or_not_theirs(): void
    {
        $this->app->make(AiClient::class)->publishChatEvent('conv-mine', 'turn', ['message' => ['id' => 'm-1']]);

        $body = $this->get('/assistant/conversations/mine/events?after=0')->assertOk()->streamedContent();
        $this->assertStringContainsString("event: turn\ndata: {\"message\":{\"id\":\"m-1\"},\"event_id\":1}", $body);
        $this->assertStringContainsString("event: idle\ndata: {\"after\":1}", $body);

        $this->get('/assistant/conversations/theirs/events')->assertNotFound();
        $this->guard->on = false;
        $this->get('/assistant/conversations/mine/events')->assertNotFound();
    }

    private function tool(string $name, ?string $token): \Illuminate\Testing\TestResponse
    {
        $body = (string) json_encode(['input' => ['n' => 1]]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->signed($body, $token) as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return parent::call('POST', '/ai-tools/'.$name, [], [], [], $server, $body);
    }
}
