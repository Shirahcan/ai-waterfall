<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\ConversationTurn;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Conversations\RowMirror;

class MirroredThread extends Model
{
    protected $table = 'mirrored_threads';

    protected $guarded = [];
}

/** A product's rows mirrored into ai-service: once each, ids remembered quietly. */
class RowMirrorTest extends TestCase
{
    private FakeAiClient $ai;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiClient;

        Schema::create('mirrored_threads', function (Blueprint $table) {
            $table->id();
            $table->string('ai_conversation_id')->nullable();
            $table->string('ai_turn_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_a_thread_is_started_once_and_remembered_without_touching_timestamps(): void
    {
        $row = MirroredThread::create([]);
        $row->forceFill(['updated_at' => '2026-01-01 00:00:00'])->saveQuietly();
        $mirror = new RowMirror($this->ai);

        $id = $mirror->thread($row, 'ai_conversation_id', 'user:1', 'client', 'Hello');
        $again = $mirror->thread($row->fresh(), 'ai_conversation_id', 'user:1', 'client', 'Hello');

        $this->assertSame($id, $again);
        $this->assertSame($id, $row->fresh()->ai_conversation_id);
        $this->assertSame('2026-01-01 00:00:00', (string) $row->fresh()->updated_at, 'bookkeeping must not reorder a list');
        $this->assertSame('Hello', $this->ai->conversations()->forOwner('user:1')[0]->title);
    }

    public function test_a_turn_is_appended_once_with_its_structure(): void
    {
        $row = MirroredThread::create([]);
        $mirror = new RowMirror($this->ai);
        $thread = $mirror->thread($row, 'ai_conversation_id', 'user:1');

        $turn = $mirror->turn($row, 'ai_turn_id', $thread, ConversationTurn::ROLE_ASSISTANT, 'An answer',
            ['structured' => ['cards' => [1]], 'persona_key' => 'porter', 'grounded' => true]);

        $this->assertNotNull($turn);
        $this->assertSame(['cards' => [1]], $turn->structured);
        $this->assertNull($mirror->turn($row->fresh(), 'ai_turn_id', $thread, ConversationTurn::ROLE_ASSISTANT, 'An answer'),
            'a row that already carries its turn is never appended twice');
        $this->assertSame(1, $this->ai->conversations()->turnCount($thread));
    }

    public function test_a_row_not_yet_inserted_carries_the_id_in_its_insert(): void
    {
        $row = new MirroredThread();
        $id = (new RowMirror($this->ai))->thread($row, 'ai_conversation_id', 'user:2');

        $this->assertFalse($row->exists);
        $this->assertSame($id, $row->ai_conversation_id);
    }
}
