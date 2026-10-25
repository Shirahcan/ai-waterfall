<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\ConversationTurn;
use Shirahcan\AiClient\CorpusFilter;
use Shirahcan\AiClient\CorpusPage;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\FakeAiConversations;
use Shirahcan\AiClient\Laravel\Conversations\HeldTurnInAiService;
use Shirahcan\AiClient\Laravel\Conversations\HeldTurnSubject;

class HeldMessage extends Model
{
    use HeldTurnInAiService;

    protected $table = 'held_messages';

    protected $guarded = [];

    public $timestamps = false;

    protected static function aiTurnSubject(): string
    {
        return HeldMessageSubject::class;
    }
}

class HeldMessageSubject implements HeldTurnSubject
{
    public static bool $on = true;

    public function column(): string
    {
        return 'ai_turn_id';
    }

    public function enabled(): bool
    {
        return self::$on;
    }

    public function hydrate(?ConversationTurn $turn, Model $model): array
    {
        return ['content' => $turn?->body, 'kind' => $turn?->structured['kind'] ?? null];
    }
}

/**
 * P21d. A product's message rows read their words from ai-service, in one call per collection.
 */
class HeldTurnTest extends TestCase
{
    private FakeAiClient $ai;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        HeldMessageSubject::$on = true;
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);

        Schema::create('held_messages', function (Blueprint $table) {
            $table->id();
            $table->string('ai_turn_id')->nullable();
            $table->text('content')->nullable();
            $table->string('kind')->nullable();
        });
    }

    private ?string $lastThread = null;

    private function row(string $said, string $stale): int
    {
        $thread = $this->ai->conversations()->start('u-1');
        $this->lastThread = $thread->id;
        $turn = $this->ai->conversations()->append($thread->id, ConversationTurn::ROLE_ASSISTANT, $said, ['kind' => 'answer']);

        return DB::table('held_messages')->insertGetId(['ai_turn_id' => $turn->id, 'content' => $stale]);
    }

    public function test_the_words_come_from_the_service_not_the_local_copy(): void
    {
        $this->row('What the service holds.', 'A stale local copy.');
        $this->row('A second answer.', 'Another stale copy.');

        $rows = HeldMessage::orderBy('id')->get();

        $this->assertSame(['What the service holds.', 'A second answer.'], $rows->pluck('content')->all());
        $this->assertSame('answer', $rows[0]->kind);
        $this->assertFalse($rows[0]->aiTurnUnavailable());
    }

    public function test_an_erased_turn_reads_as_erased(): void
    {
        $id = $this->row('Said once.', 'Local copy.');
        $turnId = (string) DB::table('held_messages')->where('id', $id)->value('ai_turn_id');
        $this->ai->conversations()->forgetTurn((string) $this->lastThread, $turnId);

        $this->assertNull(HeldMessage::findOrFail($id)->content);
    }

    public function test_an_outage_is_flagged_and_shows_no_local_words(): void
    {
        $id = $this->row('Said once.', 'Local copy.');
        $this->app->instance(AiClient::class, new class extends FakeAiClient {
            public function conversations(): FakeAiConversations
            {
                return new class extends FakeAiConversations {
                    public function turns(CorpusFilter $filter = new CorpusFilter(), int $limit = 100, ?string $before = null): CorpusPage
                    {
                        throw new AiUnavailableException('ai-service is unreachable');
                    }
                };
            }
        });

        $row = HeldMessage::findOrFail($id);

        $this->assertTrue($row->aiTurnUnavailable());
        $this->assertNull($row->content);
    }

    public function test_with_the_subject_off_the_rows_are_untouched(): void
    {
        HeldMessageSubject::$on = false;
        $id = $this->row('Said once.', 'Local copy.');

        $this->assertSame('Local copy.', HeldMessage::findOrFail($id)->content);
    }
}
