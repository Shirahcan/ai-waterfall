<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Conversation;
use Shirahcan\AiClient\ConversationTurn;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Conversations\HeldConversations;
use Shirahcan\AiClient\Laravel\Conversations\HeldConversationSubject;
use Shirahcan\AiClient\Laravel\Conversations\HeldInAiService;

class HeldThread extends Model
{
    use HeldInAiService;

    protected $table = 'held_threads';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['turn_count' => 'integer', 'last_turn_at' => 'datetime'];

    protected static function aiConversationSubject(): string
    {
        return HeldThreadSubject::class;
    }
}

class HeldThreadSubject implements HeldConversationSubject
{
    public function column(): string
    {
        return 'ai_conversation_id';
    }

    public function heldAttributes(): array
    {
        return ['title', 'turn_count', 'last_turn_at'];
    }

    public function writeThrough(): array
    {
        return ['title' => 'title'];
    }

    public function hydrate(?Conversation $c, Model $m): array
    {
        /*
         * ⚠ WHAT A ROW WHOSE THREAD IS GONE LOOKS LIKE, decided here rather than inherited.
         * Retention erases on a schedule, so this branch runs in production.
         */
        if ($c === null) {
            return ['title' => null, 'turn_count' => 0, 'last_turn_at' => null];
        }

        return [
            'title'        => $c->title,
            'turn_count'   => $c->turnCount,
            'last_turn_at' => $c->lastTurnAt?->format('Y-m-d H:i:s'),
        ];
    }
}

/**
 * The held pattern, on a real Eloquent model.
 *
 * ⚠ THIS IS THE FILE THAT EARNS THE TRAIT. It overrides the model's save path, so an untested
 * version of it is the "building on an unverified foundation" failure in its purest form. What
 * is proven here is that a collection costs ONE call, that the service's values really are the
 * model's attributes, that a held column is never written to the product's table, that a rename
 * reaches the service instead of vanishing the way `Meeting`'s did, that writing a DERIVED held
 * attribute raises rather than disappears, and that a row whose thread has been erased is a
 * state the product can see.
 */
class HeldConversationTest extends TestCase
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
        $this->app->instance(AiClient::class, $this->ai);

        Schema::create('held_threads', function (Blueprint $table) {
            $table->id();
            $table->string('user_ref');
            $table->string('ai_conversation_id')->nullable();

            // The product KEEPS the columns - a row from before the cutover still needs them -
            // and stops treating them as its own.
            $table->string('title')->nullable();
            $table->unsignedInteger('turn_count')->default(0);
            $table->timestamp('last_turn_at')->nullable();
        });
    }

    /** A row pointing at a thread, with the product's own columns deliberately stale. */
    private function row(array $overrides = []): HeldThread
    {
        $thread = $this->ai->conversations()->start('u-9', title: 'Spousal sponsorship', clientRef: 'row-' . uniqid());
        $this->ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, 'Asked.');

        $id = DB::table('held_threads')->insertGetId(array_merge([
            'user_ref'           => 'u-9',
            'ai_conversation_id' => $thread->id,
            'title'              => 'STALE, from before the cutover',
            'turn_count'         => 99,
            'last_turn_at'       => null,
        ], $overrides));

        return HeldThread::findOrFail($id);
    }

    /**
     * ⚠ ONE CALL FOR A WHOLE COLLECTION. The held pattern's entire claim; an N+1 over a network
     * passes every test and is obvious only in production.
     */
    public function test_a_collection_is_hydrated_from_the_service_in_one_call(): void
    {
        $this->row();
        $this->row();
        $this->row();

        /*
         * ⚠ A FRESH REQUEST, OR THIS TEST PROVES NOTHING. The rows were each read once while
         * being set up, so the request-scoped loader already knows all three ids and a second
         * read legitimately costs ZERO calls. Forgetting the scoped instances is what Laravel
         * itself does between requests in a long-lived worker, so this is the real boundary.
         */
        $this->app->forgetScopedInstances();
        $this->ai->transcripts()->batchReads = 0;

        $rows = HeldThread::all();

        $this->assertCount(3, $rows);
        $this->assertSame(1, $this->ai->transcripts()->batchReads, 'One call for the whole collection.');

        foreach ($rows as $row) {
            $this->assertSame('Spousal sponsorship', $row->title, 'The service value, not the stale column.');
            $this->assertSame(1, $row->turn_count);
            $this->assertNotNull($row->last_turn_at);
        }
    }

    /** The hydrated values are the attributes, so dirty tracking still means what it meant. */
    public function test_hydration_leaves_the_model_clean(): void
    {
        $row = $this->row();

        $this->assertFalse($row->isDirty());
        $this->assertSame('Spousal sponsorship', $row->getOriginal('title'));
    }

    /** A row without a conversation id keeps its own columns: nothing else holds them. */
    public function test_a_row_with_no_conversation_keeps_its_own_columns(): void
    {
        $id = DB::table('held_threads')->insertGetId([
            'user_ref' => 'u-1', 'ai_conversation_id' => null,
            'title' => 'Mine alone', 'turn_count' => 4,
        ]);

        $row = HeldThread::findOrFail($id);

        $this->assertFalse($row->isHeldInAiService());
        $this->assertSame('Mine alone', $row->title);
        $this->assertSame(4, $row->turn_count);

        // And it still saves normally, held attributes included.
        $row->title = 'Renamed locally';
        $row->save();

        $this->assertSame('Renamed locally', DB::table('held_threads')->where('id', $id)->value('title'));
        $this->assertSame([], $this->ai->transcripts()->writes);
    }

    /**
     * ⚠ THE RENAME THAT WOULD OTHERWISE HAVE VANISHED. `Meeting` over its booking wrote to a
     * column nothing read, raised no error, and the change was simply lost.
     */
    public function test_renaming_a_held_row_reaches_the_service_and_not_the_products_table(): void
    {
        $row = $this->row();
        $before = DB::table('held_threads')->where('id', $row->id)->value('title');

        $row->title = 'Renamed by the consultant';
        $row->save();

        // The service has it.
        $this->assertSame(
            'Renamed by the consultant',
            $this->ai->conversations()->byIds([$row->ai_conversation_id])[$row->ai_conversation_id]->title,
        );

        // The product's own column is untouched, still carrying its pre-cutover value.
        $this->assertSame($before, DB::table('held_threads')->where('id', $row->id)->value('title'));

        $update = collect($this->ai->transcripts()->writes)->firstWhere('update', $row->ai_conversation_id);
        $this->assertSame(['title' => 'Renamed by the consultant'], $update['changes']);
    }

    /** A change read back in the same request is the service's answer, not the stale cached one. */
    public function test_a_rename_is_visible_to_a_second_read_in_the_same_request(): void
    {
        $row = $this->row();

        $row->title = 'Renamed';
        $row->save();

        $this->assertSame('Renamed', app(HeldConversations::class)->get($row->ai_conversation_id)?->title);
    }

    /**
     * ⚠ A WRITE TO A DERIVED HELD ATTRIBUTE THROWS. Dropping it quietly is the defect; the
     * message has to say what to do instead.
     */
    public function test_writing_a_derived_held_attribute_is_refused_loudly(): void
    {
        $row = $this->row();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/turn_count.*held by ai-service/s');

        $row->turn_count = 12;
        $row->save();
    }

    /** Only held facts changed: still a write, so observers hear about it. */
    public function test_a_rename_still_counts_as_a_change_the_model_reports(): void
    {
        $row = $this->row();

        $heard = [];
        HeldThread::updated(function (HeldThread $m) use (&$heard) {
            $heard[] = $m->id;
        });

        $row->title = 'Renamed';
        $row->save();

        $this->assertSame([$row->id], $heard);
        $this->assertTrue($row->wasChanged('title'));
    }

    /** Held attributes are not written on INSERT either, when the row is born held. */
    public function test_a_row_created_already_held_does_not_store_the_held_columns(): void
    {
        $thread = $this->ai->conversations()->start('u-9', title: 'Named in the service', clientRef: 'new');

        $row = HeldThread::create([
            'user_ref'           => 'u-9',
            'ai_conversation_id' => $thread->id,
            'title'              => 'Should never be stored',
            'turn_count'         => 7,
        ]);

        $stored = DB::table('held_threads')->where('id', $row->id)->first();

        $this->assertNull($stored->title);
        $this->assertSame(0, (int) $stored->turn_count);
        $this->assertSame($thread->id, $stored->ai_conversation_id);

        /*
         * ⚠ AND THE MODEL IN HAND DOES NOT LIE. The values passed in were stripped from the
         * insert, so without the re-hydrate the object would still be reporting them while the
         * database and the service both said something else.
         */
        $this->assertSame('Named in the service', $row->title);
        $this->assertSame(0, $row->turn_count);

        // Nothing was PATCHED: creating the handle is not a change to the thread.
        $this->assertNull(collect($this->ai->transcripts()->writes)->firstWhere('update', $thread->id));
    }

    /**
     * ⚠ THE STATE EVERY PRODUCT WILL MEET AND FEW WILL HANDLE. Retention erases threads on a
     * schedule, so a row can point at nothing - and a product that lists it anyway shows
     * somebody a conversation with no messages in it.
     */
    public function test_a_row_whose_thread_was_erased_is_visibly_no_longer_held(): void
    {
        $row = $this->row();
        $conversationId = $row->ai_conversation_id;

        $this->ai->transcripts()->pretendRetentionErased($conversationId);
        app(HeldConversations::class)->forget($conversationId);

        $fresh = HeldThread::findOrFail($row->id);

        // It still POINTS at a thread...
        $this->assertTrue($fresh->isHeldInAiService());
        // ...but the thread is gone, and the two questions are different questions.
        $this->assertFalse($fresh->aiConversationStillHeld());
        $this->assertNull($fresh->aiConversation());

        // And the subject decided what that looks like.
        $this->assertNull($fresh->title);
        $this->assertSame(0, $fresh->turn_count);
    }

    /**
     * ⚠ REQUEST-SCOPED, NOT A SINGLETON. A copy that outlived the request would render a title
     * somebody renamed an hour ago, or keep showing a thread retention has erased.
     */
    public function test_the_loader_is_one_instance_per_request_and_can_be_told_to_ask_again(): void
    {
        $row = $this->row();

        $this->assertSame(app(HeldConversations::class), app(HeldConversations::class));
        $this->assertTrue(app(HeldConversations::class)->knows($row->ai_conversation_id));

        app(HeldConversations::class)->forget($row->ai_conversation_id);

        $this->assertFalse(app(HeldConversations::class)->knows($row->ai_conversation_id));
    }

    /** A second read of the same row in one request costs no second call. */
    public function test_reading_a_row_twice_asks_the_service_once(): void
    {
        $row = $this->row();

        $this->app->forgetScopedInstances();
        $this->ai->transcripts()->batchReads = 0;

        HeldThread::findOrFail($row->id);
        HeldThread::findOrFail($row->id);

        $this->assertSame(1, $this->ai->transcripts()->batchReads, 'The second read is already known.');
    }

    /*
    |--------------------------------------------------------------------------
    | The outage, which was never tested and whose blast radius was wrong
    |--------------------------------------------------------------------------
    */

    /**
     * ⚠ LOADING A HELD MODEL MUST NOT THROW WHEN ai-service IS UNREACHABLE, AND IT USED TO.
     * Hydration runs inside `newCollection()`, so the rethrow came out of EVERY Eloquent query
     * that returned a held model - including the ones that never touch a held attribute. The
     * docblock argued for it ("a stale fallback would be a LIE") and the argument was sound
     * about the VALUES and wrong about the SCOPE: in Portify it meant the authorisation check
     * that reads `user_id` threw, the scheduled prune threw, and the erasure sweep threw, so a
     * person asking to be forgotten would have had nothing erased in either store.
     *
     * No test covered this path, which is exactly why it was never noticed.
     */
    public function test_an_unreachable_service_does_not_break_loading_the_model(): void
    {
        $thread = $this->ai->conversations()->start(ownerRef: 'user-1', audience: 'consultant', title: 'Held title');
        $row = HeldThread::create(['user_ref' => 'user-1', 'ai_conversation_id' => $thread->id]);

        // Portify's own column still holds whatever it last wrote, as it does during a soak.
        DB::table('held_threads')->where('id', $row->id)->update(['title' => 'The product last wrote this']);

        $this->app->forgetScopedInstances();
        $this->ai->pretendConversationsUnavailable();

        $reloaded = HeldThread::findOrFail($row->id);

        $this->assertSame('user-1', $reloaded->user_ref);

        /*
         * ⚠ NOTHING IS INVENTED AND NOTHING IS OVERWRITTEN. The row keeps its own values rather
         * than being hydrated to "erased", because the service did not say it was erased - it
         * did not answer at all.
         */
        $this->assertSame('The product last wrote this', $reloaded->title);
        $this->assertFalse($reloaded->aiConversationFactsAreCurrent());
    }

    /**
     * ⚠ AN OUTAGE IS NOT AN ERASURE, and this is the distinction that decides whether a product
     * hides - or deletes - data it still has. `get()` returning null means the service ANSWERED
     * and does not hold the thread; unreachable means nobody asked.
     */
    public function test_an_outage_does_not_read_as_an_erasure(): void
    {
        $thread = $this->ai->conversations()->start(ownerRef: 'user-1', audience: 'consultant', title: 'Held title');
        $row = HeldThread::create(['user_ref' => 'user-1', 'ai_conversation_id' => $thread->id]);

        $this->app->forgetScopedInstances();
        $this->ai->pretendConversationsUnavailable();

        $this->assertTrue(HeldThread::findOrFail($row->id)->aiConversationStillHeld());

        // And a thread the service DOES answer about, and does not hold, still reads as erased.
        $this->app->forgetScopedInstances();
        $this->ai->pretendConversationsUnavailable(false);
        $this->ai->transcripts()->pretendRetentionErased($thread->id);

        $reloaded = HeldThread::findOrFail($row->id);

        $this->assertFalse($reloaded->aiConversationStillHeld());
        $this->assertTrue($reloaded->aiConversationFactsAreCurrent());
        $this->assertNull($reloaded->title);
    }

    /**
     * ⚠ A FAILURE RECORDS NOTHING FOR THOSE IDS, so the next read in the same request asks
     * again. Writing nulls on failure would make one unreachable moment read as a permanent
     * erasure for the rest of the request - answered from memory, with no further call and no
     * way back.
     */
    public function test_the_loader_asks_again_once_the_service_comes_back(): void
    {
        $thread = $this->ai->conversations()->start(ownerRef: 'user-1', audience: 'consultant', title: 'Held title');
        $row = HeldThread::create(['user_ref' => 'user-1', 'ai_conversation_id' => $thread->id]);

        $this->app->forgetScopedInstances();
        $this->ai->pretendConversationsUnavailable();

        $this->assertNull(HeldThread::findOrFail($row->id)->title, 'Nothing was written, so nothing was hydrated.');

        $this->ai->pretendConversationsUnavailable(false);

        // The SAME request, no scope reset: the failed ids were not cached, so it asks again.
        $this->assertSame('Held title', HeldThread::findOrFail($row->id)->title);
        $this->assertTrue(app(HeldConversations::class)->isUnavailable() === false);
    }

    /**
     * ⚠ A WRITE STILL THROWS, which is the half that must NOT degrade. A rename the service never
     * heard about is the silently-lost write `Meeting` already shipped over its booking: the
     * product's column is not written either, so the change simply vanishes with nothing said.
     */
    public function test_a_rename_during_an_outage_fails_loudly(): void
    {
        $thread = $this->ai->conversations()->start(ownerRef: 'user-1', audience: 'consultant', title: 'Held title');
        $row = HeldThread::create(['user_ref' => 'user-1', 'ai_conversation_id' => $thread->id]);

        $this->ai->pretendConversationsUnavailable();

        $this->expectException(\Shirahcan\AiClient\Exceptions\AiUnavailableException::class);

        $row->update(['title' => 'A rename nobody would ever see']);
    }
}
