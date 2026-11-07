<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Conversations\Threads\EloquentThreadIndex;
use Shirahcan\AiClient\Laravel\Conversations\Threads\ThreadIndex;
use Shirahcan\AiClient\Laravel\Conversations\Threads\ThreadRoutes;

class ThreadTestConversation extends Model
{
    use HasUuids;

    protected $table = 'assistant_conversations';

    protected $guarded = [];

    protected $casts = ['last_message_at' => 'datetime'];
}

class ThreadTestIndex extends EloquentThreadIndex
{
    public static bool $on = true;

    protected function model(): string
    {
        return ThreadTestConversation::class;
    }

    public function enabled(): bool
    {
        return self::$on;
    }
}

/**
 * The conversation list in the kit (1.53.0): one controller for every product, own rows only, pins from
 * ai-service, search mapped through the person's own rows, rename through the product's model.
 */
class ThreadRoutesTest extends TestCase
{
    private FakeAiClient $ai;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineRoutes($router): void
    {
        ThreadRoutes::mount('assistant');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('assistant_conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('user_id');
            $t->string('title')->nullable();
            $t->string('audience')->nullable();
            $t->string('ai_conversation_id')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
        });
        ThreadTestIndex::$on = true;
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $this->app->bind(ThreadIndex::class, ThreadTestIndex::class);
    }

    private function thread(string $user, string $title, string $at): ThreadTestConversation
    {
        $held = $this->ai->conversations()->start($user, 'candidate', null, $title);

        return ThreadTestConversation::create(['user_id' => $user, 'title' => $title, 'audience' => 'candidate', 'ai_conversation_id' => $held->id, 'last_message_at' => $at]);
    }

    public function test_pages_newest_first_with_pins_on_the_first_page_and_never_another_persons(): void
    {
        $old = $this->thread('u1', 'Welder offer', '2026-11-01 10:00:00');
        $this->thread('u1', 'Nurse role', '2026-11-05 10:00:00');
        $this->thread('u1', 'Interview time', '2026-11-07 10:00:00');
        $this->thread('u2', 'Somebody else', '2026-11-07 11:00:00');
        $this->ai->conversations()->update($old->ai_conversation_id, ['pinned' => true]);
        $this->actingAs(new GenericUser(['id' => 'u1']));

        $first = $this->getJson('/assistant/threads?limit=2')->assertOk()->json('data');
        $this->assertSame(['Interview time', 'Nurse role'], array_column($first['items'], 'title'));
        $this->assertSame(['Welder offer'], array_column($first['pinned'], 'title'));
        $this->assertNotNull($first['next_before']);

        $second = $this->getJson('/assistant/threads?limit=2&before='.urlencode($first['next_before']))->assertOk()->json('data');
        $this->assertSame(['Welder offer'], array_column($second['items'], 'title'));
        $this->assertTrue($second['items'][0]['pinned']);
        $this->assertSame([], $second['pinned']);
        $this->assertNull($second['next_before']);
    }

    public function test_search_rename_and_pin_stay_inside_the_persons_own_rows(): void
    {
        $mine = $this->thread('u1', 'Welder offer', '2026-11-01 10:00:00');
        $theirs = $this->thread('u2', 'Welder for them', '2026-11-02 10:00:00');
        $this->actingAs(new GenericUser(['id' => 'u1']));

        $found = $this->getJson('/assistant/threads/search?q=welder')->assertOk()->json('data.results');
        $this->assertSame([$mine->id], array_column($found, 'id'));

        $this->patchJson('/assistant/threads/'.$mine->id, ['title' => 'Welder offer (signed)', 'pinned' => true])
            ->assertOk()->assertJsonPath('data.conversation.title', 'Welder offer (signed)')->assertJsonPath('data.conversation.pinned', true);
        $this->assertSame('Welder offer (signed)', $mine->fresh()->title);

        $this->patchJson('/assistant/threads/'.$theirs->id, ['title' => 'mine now'])->assertNotFound();
        $this->patchJson('/assistant/threads/'.$mine->id, [])->assertStatus(422);
    }

    public function test_the_eleventh_pin_is_refused_in_the_services_words_and_the_switch_hides_everything(): void
    {
        $this->actingAs(new GenericUser(['id' => 'u1']));
        $rows = [];
        for ($i = 1; $i <= 11; $i++) {
            $rows[] = $this->thread('u1', "Thread {$i}", sprintf('2026-11-%02d 10:00:00', $i));
        }
        foreach (array_slice($rows, 0, 10) as $row) {
            $this->patchJson('/assistant/threads/'.$row->id, ['pinned' => true])->assertOk();
        }
        $this->patchJson('/assistant/threads/'.$rows[10]->id, ['pinned' => true])
            ->assertStatus(422)->assertJsonPath('success', false);

        ThreadTestIndex::$on = false;
        $this->getJson('/assistant/threads')->assertNotFound();
        $this->getJson('/assistant/threads/search?q=thread')->assertNotFound();
    }
}
