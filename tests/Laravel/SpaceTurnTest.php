<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Spaces\Routes\AssistantRoutes;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnEvents;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnRefused;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnResult;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnRunner;

class ScriptedTurnRunner implements TurnRunner
{
    /** 'answer' | 'stopped' | 'refused' | 'broken' */
    public static string $mode = 'answer';

    public static ?string $answeredIn = null;

    /** @var list<string> */
    public static array $cancelled = [];

    public function validate(Request $request): array
    {
        return $request->validate(['message' => ['required', 'string', 'max:2000']]);
    }

    public function openingStages(): array
    {
        return ['Understanding your question', 'Working on it'];
    }

    public function run(Request $request, array $data, ?string $conversationId, string $turnId, TurnEvents $events): ?TurnResult
    {
        self::$answeredIn = $conversationId;
        $events->token('Within ');
        $events->token('30 days.');

        return match (self::$mode) {
            'stopped' => null,
            'refused' => throw new TurnRefused('You do not have access to this conversation.'),
            'broken' => throw new \RuntimeException('a provider timed out (raw text the person must not see)'),
            default => new TurnResult($conversationId ?? 'new-thread', ['role' => 'assistant', 'content' => 'Within 30 days.']),
        };
    }

    public function cancel(Request $request, string $turnId): bool
    {
        self::$cancelled[] = $turnId;

        return true;
    }

    public function failureMessage(): string
    {
        return 'Porter could not finish answering that. Your question is saved - press Retry.';
    }
}

/**
 * The chat turn on the kit's transport (1.57, plan S2c): the events and their order, Stop, a refusal
 * read as written, a crash read as the product's sentence, and a thread outside the space refused
 * before anything streams.
 */
class SpaceTurnTest extends TestCase
{
    private FakeAiClient $ai;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('ai-client.assistant.key', 'porter');
        $app['config']->set('ai-client.spaces.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('v1/assistant')->group(fn () => AssistantRoutes::mount('space'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $this->app->bind(TurnRunner::class, ScriptedTurnRunner::class);
        ScriptedTurnRunner::$mode = 'answer';
        ScriptedTurnRunner::$answeredIn = null;
        ScriptedTurnRunner::$cancelled = [];
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function events(string $body): array
    {
        preg_match_all("/event: (\\w+)\ndata: (.*)\n\n/", $body, $m, PREG_SET_ORDER);

        return array_map(fn ($e) => [$e[1], json_decode($e[2], true)], $m);
    }

    private function me(): static
    {
        return $this->actingAs(new GenericUser(['id' => 'u-1']));
    }

    public function test_a_turn_streams_its_stages_words_and_answer_in_order_and_is_reopened_next_time(): void
    {
        $space = $this->ai->spaces()->resolve('porter', 'user', 'u-1', null)['id'];
        $thread = $this->ai->spaces()->start($space)['conversation']['id'];

        $body = $this->me()->post('/v1/assistant/space/chat/stream', ['message' => 'When?', 'conversation_id' => $thread, 'turn_id' => 'turn-1'])
            ->assertOk()->streamedContent();
        $events = $this->events($body);

        $this->assertSame(['stage', 'stage', 'token', 'token', 'message'], array_column($events, 0));
        $this->assertSame(['label' => 'Understanding your question'], $events[0][1]);
        $this->assertSame(['label' => 'Working on it', 'turn_id' => 'turn-1'], $events[1][1]);
        $this->assertSame($thread, $events[4][1]['conversation_id']);
        $this->assertSame($thread, ScriptedTurnRunner::$answeredIn);
        $this->assertSame($thread, $this->ai->spaces()->state($space)['active_conversation_id']);
    }

    public function test_a_stopped_turn_sends_no_answer_and_stop_reaches_the_runner(): void
    {
        ScriptedTurnRunner::$mode = 'stopped';
        $events = $this->events($this->me()->post('/v1/assistant/space/chat/stream', ['message' => 'x'])->streamedContent());
        $this->assertNotContains('message', array_column($events, 0));

        $this->me()->deleteJson('/v1/assistant/space/chat/stream/turn-9')->assertOk()->assertJsonPath('data.cancelled', true);
        $this->assertSame(['turn-9'], ScriptedTurnRunner::$cancelled);
    }

    public function test_a_refusal_is_read_as_written_and_a_crash_as_the_products_sentence(): void
    {
        ScriptedTurnRunner::$mode = 'refused';
        $refused = $this->events($this->me()->post('/v1/assistant/space/chat/stream', ['message' => 'x'])->streamedContent());
        $this->assertSame(['error', ['message' => 'You do not have access to this conversation.']], end($refused));

        ScriptedTurnRunner::$mode = 'broken';
        $broken = $this->events($this->me()->post('/v1/assistant/space/chat/stream', ['message' => 'x'])->streamedContent());
        $this->assertSame('Porter could not finish answering that. Your question is saved - press Retry.', end($broken)[1]['message']);
    }

    /** ⚠ THE CONTROL: another person's thread is refused before the runner is ever called. */
    public function test_another_persons_thread_is_refused_before_anything_streams(): void
    {
        $theirs = $this->ai->spaces()->start($this->ai->spaces()->resolve('porter', 'user', 'u-2', null)['id'])['conversation']['id'];

        $this->me()->postJson('/v1/assistant/space/chat/stream', ['message' => 'x', 'conversation_id' => $theirs])->assertNotFound();
        $this->assertNull(ScriptedTurnRunner::$answeredIn);
    }

    public function test_a_bad_request_is_a_plain_422_and_no_runner_means_no_route(): void
    {
        $this->me()->postJson('/v1/assistant/space/chat/stream', ['message' => ''])->assertStatus(422);

        $this->app->offsetUnset(TurnRunner::class);
        $this->me()->postJson('/v1/assistant/space/chat/stream', ['message' => 'x'])->assertNotFound();
    }
}
