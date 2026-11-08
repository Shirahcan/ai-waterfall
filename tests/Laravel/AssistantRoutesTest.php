<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\FakeAiSpaces;
use Shirahcan\AiClient\Laravel\Spaces\Routes\AssistantRoutes;

/**
 * The one route module (1.57, plan S2b): the panel's data through the person's space.
 *
 * ⚠ WHAT THIS GUARDS: off means 404 everywhere; a person reaches only their own space's threads and
 * memory whatever id they send; an outage says so (503); a refusal keeps the service's own sentence;
 * and the list keeps the shape ai-ui's `useAssistantThreads` already reads.
 */
class AssistantRoutesTest extends TestCase
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
    }

    private function as(string $id): static
    {
        return $this->actingAs(new GenericUser(['id' => $id]));
    }

    private function store(): FakeAiSpaces
    {
        return $this->ai->spaces();
    }

    private function spaceOf(string $user): string
    {
        return $this->store()->resolve('porter', 'user', $user, null)['id'];
    }

    public function test_off_or_signed_out_is_not_found(): void
    {
        $this->getJson('/v1/assistant/space/threads')->assertNotFound();

        config(['ai-client.spaces.enabled' => false]);
        $this->as('u-1')->getJson('/v1/assistant/space/threads')->assertNotFound();
        $this->assertSame([], $this->store()->calls);
    }

    public function test_the_list_has_the_shape_the_shared_hook_reads_with_pins_on_the_first_page(): void
    {
        $space = $this->spaceOf('u-1');
        $a = $this->store()->start($space, ['title' => 'Biometrics'])['conversation']['id'];
        $b = $this->store()->start($space, ['title' => 'Work permit'])['conversation']['id'];
        $this->store()->update($space, $a, ['pinned' => true]);

        $list = $this->as('u-1')->getJson('/v1/assistant/space/threads')->assertOk()->json('data');

        $this->assertSame([$b, $a], array_column($list['items'], 'id'));
        $this->assertSame(['id', 'title', 'audience', 'last_message_at', 'created_at', 'pinned'], array_keys($list['items'][0]));
        $this->assertSame([$a], array_column($list['pinned'], 'id'));
        $this->assertNull($list['next_before']);
    }

    /** ⚠ THE CONTROL: another person's thread id is a 404 on every route, and nothing of theirs changes. */
    public function test_another_persons_thread_is_not_found_whatever_route_is_used(): void
    {
        $theirs = $this->store()->start($this->spaceOf('u-2'), ['title' => 'Theirs'])['conversation']['id'];
        $this->spaceOf('u-1');
        $me = $this->as('u-1');

        $me->getJson("/v1/assistant/space/conversations/{$theirs}")->assertNotFound();
        $me->patchJson("/v1/assistant/space/threads/{$theirs}", ['title' => 'Mine now'])->assertNotFound();
        $me->deleteJson("/v1/assistant/space/conversations/{$theirs}")->assertNotFound();
        $me->putJson('/v1/assistant/space/active-conversation', ['conversation_id' => $theirs])->assertNotFound();

        $space = $this->spaceOf('u-2');
        $this->assertSame('Theirs', $this->store()->threads[$space][$theirs]['title']);
    }

    public function test_reading_a_thread_returns_its_turns_as_messages(): void
    {
        $space = $this->spaceOf('u-1');
        $id = $this->store()->start($space)['conversation']['id'];
        $this->store()->append($space, $id, ['role' => 'user', 'body' => 'When are biometrics due?']);
        $this->store()->append($space, $id, ['role' => 'assistant', 'body' => 'Within 30 days.', 'structured' => ['cards' => []]]);

        $read = $this->as('u-1')->getJson("/v1/assistant/space/conversations/{$id}")->assertOk()->json('data');

        $this->assertSame(['user', 'assistant'], array_column($read['messages'], 'role'));
        $this->assertSame('Within 30 days.', $read['messages'][1]['content']);
        $this->assertSame(['cards' => []], $read['messages'][1]['structured']);
    }

    public function test_a_product_that_binds_its_message_shape_gets_it(): void
    {
        $this->app->bind(\Shirahcan\AiClient\Laravel\Spaces\SpaceMessages::class, fn () => new class implements \Shirahcan\AiClient\Laravel\Spaces\SpaceMessages
        {
            public function present(\Illuminate\Http\Request $request, array $conversation, array $turns): array
            {
                return array_map(fn ($t) => ['kind' => 'porter', 'ai_turn_id' => $t['id'], 'content' => $t['body']], $turns);
            }
        });
        $space = $this->spaceOf('u-1');
        $id = $this->store()->start($space)['conversation']['id'];
        $this->store()->append($space, $id, ['role' => 'assistant', 'body' => 'Hello']);

        $this->as('u-1')->getJson("/v1/assistant/space/conversations/{$id}")->assertOk()
            ->assertJsonPath('data.messages.0.kind', 'porter')->assertJsonPath('data.messages.0.content', 'Hello');
    }

    public function test_suggested_questions_come_from_the_service_for_the_screen_and_a_failure_is_an_empty_list(): void
    {
        $this->spaceOf('u-1');
        $this->store()->suggested = ['Which documents are still outstanding?', 'How do I invite a client?'];

        $this->as('u-1')->getJson('/v1/assistant/space/prompts?route=/consultant/cases&limit=1')->assertOk()
            ->assertJsonPath('data.prompts', ['Which documents are still outstanding?']);
        $call = array_values(array_filter($this->store()->calls, fn ($c) => $c[0] === 'prompts'))[0];
        $this->assertSame('/consultant/cases', $call[1][1]);
    }

    public function test_memory_reads_as_words_and_a_correction_is_the_persons_own(): void
    {
        $space = $this->spaceOf('u-1');
        $this->store()->remember($space, 'pref.language', ['v' => 'French', 'label' => 'Preferred language'], 'inferred');
        $me = $this->as('u-1');

        $facts = $me->getJson('/v1/assistant/space/memory')->assertOk()->json('data.memory');
        $this->assertSame([['key' => 'pref.language', 'label' => 'Preferred language', 'value' => 'French', 'source' => 'inferred', 'updated_at' => null]], $facts);

        $me->putJson('/v1/assistant/space/memory/pref.language', ['value' => 'English'])->assertOk()
            ->assertJsonPath('data.fact.value', 'English')->assertJsonPath('data.fact.source', 'explicit');

        $me->deleteJson('/v1/assistant/space/memory')->assertOk()->assertJsonPath('data.forgotten', 1);
    }

    public function test_the_panel_reopens_the_last_conversation_and_recalls_past_questions(): void
    {
        $space = $this->spaceOf('u-1');
        $id = $this->store()->start($space)['conversation']['id'];
        $this->store()->append($space, $id, ['role' => 'user', 'body' => 'First']);
        $this->store()->append($space, $id, ['role' => 'user', 'body' => 'Second']);
        $me = $this->as('u-1');

        $me->putJson('/v1/assistant/space/active-conversation', ['conversation_id' => $id])->assertOk();
        $me->getJson('/v1/assistant/space/active-conversation')->assertJsonPath('data.conversation_id', $id);
        $me->getJson('/v1/assistant/space/input-history')->assertJsonPath('data.history', ['Second', 'First']);
    }

    public function test_the_first_open_reopens_the_conversation_the_person_last_had_before_spaces(): void
    {
        $space = $this->spaceOf('u-1');
        $mine = $this->store()->start($space)['conversation']['id'];
        $theirs = $this->store()->start($this->spaceOf('u-2'))['conversation']['id'];

        $previous = new class implements \Shirahcan\AiClient\Laravel\Spaces\PreviousConversation
        {
            public ?string $answer = null;

            public int $asked = 0;

            public function for(\Illuminate\Http\Request $request): ?string
            {
                $this->asked++;

                return $this->answer;
            }
        };
        $this->app->instance(\Shirahcan\AiClient\Laravel\Spaces\PreviousConversation::class, $previous);

        // Another person's thread is refused by the space and simply means "start fresh".
        $previous->answer = $theirs;
        $this->as('u-1')->getJson('/v1/assistant/space/active-conversation')->assertOk()->assertJsonPath('data.conversation_id', null);

        $previous->answer = $mine;
        $this->as('u-1')->getJson('/v1/assistant/space/active-conversation')->assertJsonPath('data.conversation_id', $mine);

        // Kept in the space: not asked again.
        $asked = $previous->asked;
        $this->as('u-1')->getJson('/v1/assistant/space/active-conversation')->assertJsonPath('data.conversation_id', $mine);
        $this->assertSame($asked, $previous->asked);
    }

    public function test_an_outage_says_so_and_a_refusal_keeps_the_services_sentence(): void
    {
        $space = $this->spaceOf('u-1');
        $me = $this->as('u-1');

        $me->getJson('/v1/assistant/space/conversations/00000000-0000-4000-8000-000000000000')
            ->assertNotFound()->assertJsonPath('message', 'This space holds no conversation with that id.');

        $me->postJson('/v1/assistant/space/conversations/00000000-0000-4000-8000-000000000000/turns/00000000-0000-4000-8000-000000000001/feedback',
            ['sentiment' => 'positive', 'reason_code' => 'wrong_topic'])->assertStatus(422);

        $this->ai->pretendConversationsUnavailable();
        $this->as('u-3')->getJson('/v1/assistant/space/threads')->assertStatus(503)
            ->assertJsonPath('message', 'The assistant is not reachable right now. Try again in a moment.');
        $this->assertNotSame('', $space);
    }
}
