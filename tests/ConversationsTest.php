<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\ConversationTurn;
use Shirahcan\AiClient\FakeAiClient;

/**
 * The conversation store, through the client and through the fake.
 *
 * ⚠ WHAT IS WORTH TESTING IN A CLIENT. Not that a POST is a POST - that the RULES a consumer
 * will get wrong are carried faithfully: that a `clientRef` is sent so a retry cannot duplicate
 * a person's question, that an erasure's filter survives the DELETE, that a structured answer
 * stays structured on the wire, and that the fake refuses what the real service refuses. A fake
 * that is more permissive than the service is worse than none: it turns a product's test suite
 * into evidence for a behaviour production will reject.
 */
class ConversationsTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    private function thread(array $overrides = []): array
    {
        return array_merge([
            'id' => 'c-1', 'product' => 'portify', 'owner_ref' => 'u-9', 'audience' => 'consultant',
            'subject_ref' => 'case-9', 'client_ref' => null, 'title' => 'Spousal sponsorship',
            'turn_count' => 2, 'last_turn_at' => '2026-10-21T10:00:00+00:00',
            'retention_until' => '2027-10-21T10:00:00+00:00', 'created_at' => '2026-10-20T09:00:00+00:00',
        ], $overrides);
    }

    public function test_starting_a_thread_sends_the_product_s_own_references(): void
    {
        $ai = $this->client([$this->json(['conversation' => $this->thread(['client_ref' => 'row-5']), 'created' => true])]);

        $thread = $ai->conversations()->start(
            ownerRef: 'u-9', audience: 'consultant', subjectRef: 'case-9', title: 'Spousal sponsorship', clientRef: 'row-5',
        );

        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertSame('/api/v1/conversations', $this->sent[0]->getUri()->getPath());
        $this->assertSame([
            'owner_ref' => 'u-9', 'audience' => 'consultant', 'subject_ref' => 'case-9',
            'title' => 'Spousal sponsorship', 'client_ref' => 'row-5',
        ], $this->body());

        $this->assertSame('c-1', $thread->id);
        $this->assertSame(2, $thread->turnCount);
        $this->assertSame('2026-10-21', $thread->lastTurnAt?->format('Y-m-d'));
    }

    /**
     * ⚠ THE SIX FIELDS STAY SIX FIELDS. `ReeveAnswer` keeps a ledger figure and a regulation
     * apart because a prompt asking a model to hold them apart is a request, not a control.
     */
    public function test_a_structured_answer_is_sent_as_structure_and_read_back_as_structure(): void
    {
        $structured = [
            'what_the_books_say' => 'The trust difference in CAD is 18,595.00.',
            'what_the_rule_says' => 'A firm must account separately for each client.',
        ];

        $ai = $this->client([$this->json(['turn' => [
            'id' => 't-1', 'role' => 'assistant', 'body' => null, 'structured' => $structured,
            'refused' => false, 'created_at' => '2026-10-21T10:00:00+00:00',
        ], 'created' => true])]);

        $turn = $ai->conversations()->append('c-1', ConversationTurn::ROLE_ASSISTANT, structured: $structured);

        $this->assertSame($structured, $this->body()['structured']);
        $this->assertSame($structured, $turn->structured);
        $this->assertNull($turn->body);
        $this->assertTrue($turn->hasStructure());
    }

    /**
     * ⚠ AN EMPTY ARRAY IS NOT STRUCTURE. Sent as `[]` it would satisfy the service's
     * "one of the two is required" rule while carrying nothing, and the refusal would come back
     * as a validation error about the wrong field.
     */
    public function test_an_empty_structured_array_is_not_sent_as_structure(): void
    {
        $ai = $this->client([$this->json(['turn' => ['id' => 't-1', 'role' => 'user', 'body' => 'Asked.'], 'created' => true])]);

        $ai->conversations()->append('c-1', ConversationTurn::ROLE_USER, 'Asked.', structured: []);

        $this->assertArrayNotHasKey('structured', $this->body());
        $this->assertSame('Asked.', $this->body()['body']);
    }

    /** A refusal is a turn, and it is recorded as what was said. */
    public function test_a_refusal_is_sent_as_a_turn(): void
    {
        $ai = $this->client([$this->json(['turn' => [
            'id' => 't-2', 'role' => 'assistant', 'body' => 'Not answerable from the books.', 'refused' => true,
        ], 'created' => true])]);

        $turn = $ai->conversations()->append('c-1', ConversationTurn::ROLE_ASSISTANT, 'Not answerable from the books.', refused: true);

        $this->assertTrue($this->body()['refused']);
        $this->assertTrue($turn->refused);
    }

    public function test_reading_a_page_carries_the_cursor_and_the_limit(): void
    {
        $ai = $this->client([$this->json([
            'conversation' => $this->thread(),
            'turns' => [
                ['id' => 't-1', 'role' => 'user', 'body' => 'One.'],
                ['id' => 't-2', 'role' => 'assistant', 'body' => 'Two.'],
            ],
            'has_more' => true,
            'next_before' => 't-1',
        ])]);

        $page = $ai->conversations()->read('c-1', before: 't-9', limit: 2);

        $query = $this->sent[0]->getUri()->getQuery();
        $this->assertStringContainsString('limit=2', $query);
        $this->assertStringContainsString('before=t-9', $query);

        $this->assertCount(2, $page->turns);
        $this->assertTrue($page->hasMore);
        $this->assertSame('t-1', $page->nextBefore);
        $this->assertCount(1, $page->fromTheAssistant());
    }

    /** The page cap is the service's, and the client does not ask for more than it will give. */
    public function test_a_page_larger_than_the_service_allows_is_clamped(): void
    {
        $ai = $this->client([$this->json(['conversation' => $this->thread(), 'turns' => []])]);

        $ai->conversations()->read('c-1', limit: 5000);

        $this->assertStringContainsString('limit=200', $this->sent[0]->getUri()->getQuery());
    }

    /**
     * ⚠ THE BATCH READ IS WHAT MAKES THE HELD PATTERN AFFORDABLE, and the missing id is the
     * answer rather than an error: the service no longer holds that thread.
     */
    public function test_many_threads_are_hydrated_in_one_call_and_a_missing_one_is_simply_absent(): void
    {
        $ai = $this->client([$this->json(['conversations' => [$this->thread(['id' => 'c-1'])]])]);

        $found = $ai->conversations()->byIds(['c-1', 'c-gone', 'c-1', '']);

        $this->assertCount(1, $this->sent, 'One call, not one per id.');
        $this->assertSame(['c-1'], array_keys($found));

        $query = urldecode($this->sent[0]->getUri()->getQuery());
        $this->assertStringContainsString('ids[0]=c-1', $query);
        $this->assertStringContainsString('ids[1]=c-gone', $query);
    }

    public function test_an_empty_batch_asks_the_service_nothing(): void
    {
        $ai = $this->client([]);

        $this->assertSame([], $ai->conversations()->byIds([]));
        $this->assertSame([], $this->sent);
    }

    /** Only the three editable fields are sent; anything else a caller passes is dropped. */
    public function test_an_update_sends_only_the_fields_the_service_will_accept(): void
    {
        $ai = $this->client([$this->json(['conversation' => $this->thread(['title' => 'Renamed'])])]);

        $ai->conversations()->update('c-1', [
            'title' => 'Renamed',
            'turn_count' => 99,
            'owner_ref' => 'somebody-else',
        ]);

        $this->assertSame('PATCH', $this->sent[0]->getMethod());
        $this->assertSame(['title' => 'Renamed'], $this->body());
    }

    /**
     * ⚠ THE FILTER HAS TO SURVIVE THE DELETE. A DELETE body is the request shape clients and
     * proxies are least consistent about forwarding, and a dropped filter would be a request to
     * erase the product's entire history. The service refuses an unfiltered one, so this is
     * belt and braces - and the query string is what makes it unnecessary.
     */
    public function test_an_erasure_by_person_carries_its_filter_in_the_query(): void
    {
        $ai = $this->client([$this->json(['erased' => true, 'conversations' => 3, 'turns' => 17])]);

        $receipt = $ai->conversations()->eraseForOwner('u-9');

        $this->assertSame('DELETE', $this->sent[0]->getMethod());
        $this->assertSame('/api/v1/conversations', $this->sent[0]->getUri()->getPath());
        $this->assertStringContainsString('owner_ref=u-9', urldecode($this->sent[0]->getUri()->getQuery()));

        $this->assertSame(['erased' => true, 'conversations' => 3, 'turns' => 17], $receipt);
    }

    public function test_an_erasure_by_subject_carries_its_own_filter(): void
    {
        $ai = $this->client([$this->json(['erased' => true, 'conversations' => 2, 'turns' => 9])]);

        $ai->conversations()->eraseForSubject('case-9');

        $this->assertStringContainsString('subject_ref=case-9', urldecode($this->sent[0]->getUri()->getQuery()));
    }

    public function test_erasing_one_thread_names_it_in_the_path(): void
    {
        $ai = $this->client([$this->json(['erased' => true, 'conversations' => 1, 'turns' => 4])]);

        $receipt = $ai->conversations()->erase('c-1');

        $this->assertSame('/api/v1/conversations/c-1', $this->sent[0]->getUri()->getPath());
        $this->assertSame(4, $receipt['turns']);
    }

    /** A thread may not have been named yet, and that is not an error to paper over. */
    public function test_a_thread_with_no_title_reads_back_as_null_rather_than_as_its_id(): void
    {
        $ai = $this->client([$this->json(['conversations' => [$this->thread(['title' => null, 'turn_count' => 0, 'last_turn_at' => null])]])]);

        $threads = $ai->conversations()->forOwner('u-9');

        $this->assertNull($threads[0]->title);
        $this->assertFalse($threads[0]->hasTurns());
        $this->assertNull($threads[0]->lastTurnAt);
    }

    /*
    |--------------------------------------------------------------------------
    | The fake, which must refuse what the service refuses
    |--------------------------------------------------------------------------
    */

    public function test_the_fake_returns_the_first_turn_for_a_repeated_client_ref(): void
    {
        $ai = new FakeAiClient;

        $thread = $ai->conversations()->start('u-9', clientRef: 'row-5');

        $first = $ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, 'Asked once.', clientRef: 'm-1');
        $retry = $ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, 'Asked once.', clientRef: 'm-1');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, $ai->transcripts()->turnCount($thread->id));
    }

    public function test_the_fake_returns_the_same_thread_for_a_repeated_client_ref(): void
    {
        $ai = new FakeAiClient;

        $first = $ai->conversations()->start('u-9', clientRef: 'row-5');
        $again = $ai->conversations()->start('u-9', clientRef: 'row-5');

        $this->assertSame($first->id, $again->id);
    }

    /** ⚠ Counted, not incremented: the rule the real store is built on. */
    public function test_the_fake_recounts_the_turns_after_one_is_removed(): void
    {
        $ai = new FakeAiClient;

        $thread = $ai->conversations()->start('u-9');
        $one = $ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, 'One.');
        $ai->conversations()->append($thread->id, ConversationTurn::ROLE_ASSISTANT, 'Two.');

        $this->assertSame(2, $ai->conversations()->byIds([$thread->id])[$thread->id]->turnCount);

        $ai->transcripts()->forgetTurn($thread->id, $one->id);

        $this->assertSame(1, $ai->conversations()->byIds([$thread->id])[$thread->id]->turnCount);
    }

    public function test_the_fake_refuses_a_turn_with_neither_prose_nor_structure(): void
    {
        $ai = new FakeAiClient;
        $thread = $ai->conversations()->start('u-9');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/neither body nor structured/');

        $ai->conversations()->append($thread->id, ConversationTurn::ROLE_ASSISTANT);
    }

    public function test_the_fake_refuses_a_cursor_it_does_not_recognise(): void
    {
        $ai = new FakeAiClient;
        $thread = $ai->conversations()->start('u-9');
        $ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, 'One.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown_cursor/');

        $ai->conversations()->read($thread->id, before: 'turn-from-another-thread');
    }

    public function test_the_fake_pages_backwards_with_nothing_skipped_or_repeated(): void
    {
        $ai = new FakeAiClient;
        $thread = $ai->conversations()->start('u-9');

        $said = [];

        for ($i = 1; $i <= 7; $i++) {
            $said[] = "turn {$i}";
            $ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, "turn {$i}");
        }

        $collected = [];
        $before = null;
        $pages = 0;

        do {
            $page = $ai->conversations()->read($thread->id, before: $before, limit: 2);
            $collected = array_merge(array_map(fn ($t) => $t->body, $page->turns), $collected);
            $before = $page->nextBefore;
            $pages++;
        } while ($before !== null && $pages < 10);

        $this->assertSame($said, $collected);
        $this->assertSame(4, $pages);
    }

    /** ⚠ The branch nobody writes by accident: the thread is gone, and the row still points at it. */
    public function test_the_fake_can_pretend_retention_erased_a_thread(): void
    {
        $ai = new FakeAiClient;

        $thread = $ai->conversations()->start('u-9');
        $ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, 'Said something.');

        $ai->transcripts()->pretendRetentionErased($thread->id);

        $this->assertSame([], $ai->conversations()->byIds([$thread->id]));
    }

    public function test_the_fake_orders_a_thread_with_no_turns_above_an_older_used_one(): void
    {
        $ai = new FakeAiClient;

        $older = $ai->conversations()->start('u-9', clientRef: 'older');
        $ai->conversations()->append($older->id, ConversationTurn::ROLE_USER, 'Used.');

        $fresh = $ai->conversations()->start('u-9', clientRef: 'fresh');

        $threads = $ai->conversations()->forOwner('u-9');

        $this->assertSame($fresh->id, $threads[0]->id);
        $this->assertSame($older->id, $threads[1]->id);
    }

    public function test_the_fake_counts_refusals_per_audience_like_the_service_does(): void
    {
        $ai = new FakeAiClient;

        $client = $ai->conversations()->start('u-1', audience: 'client', clientRef: 'a');
        $ai->conversations()->append($client->id, ConversationTurn::ROLE_ASSISTANT, 'Answered.');

        $consultant = $ai->conversations()->start('u-2', audience: 'consultant', clientRef: 'b');
        $ai->conversations()->append($consultant->id, ConversationTurn::ROLE_ASSISTANT, 'Ask your consultant.', refused: true);

        $stats = $ai->conversations()->stats();

        $this->assertSame(1, $stats['turns']['refused']);
        $this->assertSame(1, $stats['turns']['refused_by_audience']['consultant']);
        $this->assertArrayNotHasKey('client', $stats['turns']['refused_by_audience']);
        $this->assertSame(0.5, $stats['turns']['refusal_rate']);
    }

    public function test_the_fake_erases_a_person_s_threads_and_leaves_the_others(): void
    {
        $ai = new FakeAiClient;

        $theirs = $ai->conversations()->start('u-9', clientRef: 'a');
        $ai->conversations()->append($theirs->id, ConversationTurn::ROLE_USER, 'Private.');
        $other = $ai->conversations()->start('u-10', clientRef: 'b');

        $receipt = $ai->conversations()->eraseForOwner('u-9');

        $this->assertSame(['erased' => true, 'conversations' => 1, 'turns' => 1], $receipt);
        $this->assertSame([$other->id], array_keys($ai->conversations()->byIds([$theirs->id, $other->id])));
    }
}
