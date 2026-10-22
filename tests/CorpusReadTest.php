<?php

namespace Shirahcan\AiClient\Tests;

use DateTimeImmutable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\ConversationTurn;
use Shirahcan\AiClient\CorpusFilter;
use Shirahcan\AiClient\FakeAiClient;

/**
 * The corpus read: a product's turns from across its threads, through the client and the fake.
 *
 * ⚠ WHAT IS WORTH TESTING HERE. That `grounded: false` reaches the wire, because it is the
 * backlog itself and this client has already shipped a bug that dropped it; that the paging loop
 * stops; and that the fake filters exactly as the service does, since a fake more permissive
 * than the service turns a product's suite into evidence for behaviour production will reject.
 */
class CorpusReadTest extends TestCase
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

    private function query(int $i = 0): string
    {
        return urldecode($this->sent[$i]->getUri()->getQuery());
    }

    /** @param array<string, mixed> $over */
    private function turn(array $over = []): array
    {
        return array_merge([
            'id'              => 'turn-1',
            'conversation_id' => 'thread-1',
            'audience'        => 'consultant',
            'owner_ref'       => 'user-1',
            'role'            => ConversationTurn::ROLE_ASSISTANT,
            'body'            => 'An answer',
            'grounded'        => false,
            'provenance_class' => 'unknown',
            'created_at'      => '2026-10-20T09:00:00+00:00',
        ], $over);
    }

    /**
     * ⚠ THE FALSE HAS TO REACH THE WIRE. `grounded=false` is the one value this filter exists
     * for - the replies that were not grounded are the backlog - and an `array_filter` on
     * truthiness drops it. This client shipped exactly that in `append()` for one commit, where
     * the resulting rate looked HEALTHIER the worse the assistant got.
     */
    public function test_a_false_filter_is_sent_rather_than_dropped(): void
    {
        $ai = $this->client([$this->json(['turns' => [$this->turn()], 'has_more' => false, 'next_before' => null])]);

        $page = $ai->conversations()->turns(new CorpusFilter(
            audience: 'consultant',
            role: ConversationTurn::ROLE_ASSISTANT,
            grounded: false,
            since: new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
        ));

        $query = $this->query();

        $this->assertStringContainsString('grounded=0', $query);
        $this->assertStringContainsString('audience=consultant', $query);
        $this->assertStringContainsString('role=assistant', $query);
        $this->assertStringContainsString('since=2026-10-01T00:00:00+00:00', $query);

        // And the thread facts the caller needs to file a gap arrive on the turn itself.
        $this->assertSame('thread-1', $page->turns[0]->conversationId);
        $this->assertSame('consultant', $page->turns[0]->audience);
        $this->assertFalse($page->turns[0]->grounded);
    }

    public function test_a_filter_nobody_set_sends_no_filter_at_all(): void
    {
        $ai = $this->client([$this->json(['turns' => [], 'has_more' => false])]);

        $ai->conversations()->turns();

        // Only the limit, so a product reading its whole corpus does not accidentally narrow it.
        $this->assertSame('limit=100', $this->query());
    }

    public function test_the_page_loop_pages_and_then_stops(): void
    {
        $ai = $this->client([
            $this->json([
                'turns'       => [$this->turn(['id' => 'turn-3']), $this->turn(['id' => 'turn-2'])],
                'has_more'    => true,
                'next_before' => 'turn-2',
            ]),
            $this->json([
                'turns'       => [$this->turn(['id' => 'turn-1'])],
                'has_more'    => false,
                'next_before' => null,
            ]),
        ]);

        $ids = [];

        foreach ($ai->conversations()->eachTurn(perPage: 2) as $turn) {
            $ids[] = $turn->id;
        }

        $this->assertSame(['turn-3', 'turn-2', 'turn-1'], $ids);
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('before=turn-2', $this->query(1));
    }

    /**
     * ⚠ A STOP, NOT A PAGE SIZE. A weekly report over a year of transcripts must be able to say
     * "the most recent 2,000 and no more" without the caller counting - and without fetching the
     * rest of the corpus to discard it.
     */
    public function test_a_maximum_stops_the_sweep_mid_page(): void
    {
        $ai = $this->client([
            $this->json([
                'turns'       => [$this->turn(['id' => 'a']), $this->turn(['id' => 'b']), $this->turn(['id' => 'c'])],
                'has_more'    => true,
                'next_before' => 'c',
            ]),
        ]);

        $ids = [];

        foreach ($ai->conversations()->eachTurn(perPage: 3, maxTurns: 2) as $turn) {
            $ids[] = $turn->id;
        }

        $this->assertSame(['a', 'b'], $ids);
        $this->assertCount(1, $this->sent, 'The sweep asked for a second page it had been told to stop before.');
    }

    public function test_an_append_now_carries_the_threads_summary_back(): void
    {
        $ai = $this->client([
            $this->json([
                'turn'         => $this->turn(['id' => 'turn-9']),
                'conversation' => [
                    'id'           => 'thread-1',
                    'title'        => 'Spousal sponsorship',
                    'turn_count'   => 7,
                    'last_turn_at' => '2026-10-20T09:00:00+00:00',
                ],
                'created' => true,
            ], 201),
        ]);

        $turn = $ai->conversations()->append('thread-1', ConversationTurn::ROLE_USER, 'A question');

        $this->assertSame('turn-9', $turn->id);
        $this->assertNotNull($turn->conversation);
        $this->assertSame(7, $turn->conversation->turnCount);
        $this->assertSame('2026-10-20T09:00:00+00:00', $turn->conversation->lastTurnAt?->format(DATE_ATOM));
    }

    public function test_an_append_without_a_summary_still_returns_the_turn(): void
    {
        $ai = $this->client([$this->json(['turn' => $this->turn(['id' => 'turn-9'])], 201)]);

        $turn = $ai->conversations()->append('thread-1', ConversationTurn::ROLE_USER, 'A question');

        $this->assertSame('turn-9', $turn->id);
        $this->assertNull($turn->conversation);
    }

    // ---------------------------------------------------------------- the fake

    private function fake(): FakeAiClient
    {
        return new FakeAiClient;
    }

    public function test_the_fake_returns_this_persons_questions_across_their_threads(): void
    {
        $ai = $this->fake();
        $conversations = $ai->conversations();

        $first = $conversations->start(ownerRef: 'user-1', audience: 'consultant');
        $second = $conversations->start(ownerRef: 'user-1', audience: 'consultant');
        $other = $conversations->start(ownerRef: 'user-2', audience: 'consultant');

        $conversations->append($first->id, ConversationTurn::ROLE_USER, 'First question',
            occurredAt: new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
        $conversations->append($first->id, ConversationTurn::ROLE_ASSISTANT, 'An answer',
            occurredAt: new DateTimeImmutable('2026-10-01T09:00:05+00:00'));
        $conversations->append($second->id, ConversationTurn::ROLE_USER, 'Second question',
            occurredAt: new DateTimeImmutable('2026-10-02T09:00:00+00:00'));
        $conversations->append($other->id, ConversationTurn::ROLE_USER, 'Somebody else',
            occurredAt: new DateTimeImmutable('2026-10-03T09:00:00+00:00'));

        $page = $conversations->turns(new CorpusFilter(ownerRef: 'user-1', role: ConversationTurn::ROLE_USER));

        $this->assertSame(['Second question', 'First question'], $page->bodies());
        $this->assertSame($second->id, $page->turns[0]->conversationId);
        $this->assertSame('consultant', $page->turns[0]->audience);
    }

    public function test_the_fake_does_not_drop_a_false_filter_either(): void
    {
        $ai = $this->fake();
        $conversations = $ai->conversations();

        $thread = $conversations->start(ownerRef: 'user-1', audience: 'client');

        $conversations->append($thread->id, ConversationTurn::ROLE_ASSISTANT, 'Grounded',
            grounded: true, provenanceClass: 'case_record');
        $conversations->append($thread->id, ConversationTurn::ROLE_ASSISTANT, 'Invented',
            grounded: false, provenanceClass: 'unknown');
        $conversations->append($thread->id, ConversationTurn::ROLE_USER, 'A question');

        $this->assertSame(
            ['Invented'],
            $conversations->turns(new CorpusFilter(grounded: false))->bodies(),
        );

        $this->assertSame(
            ['Grounded'],
            $conversations->turns(new CorpusFilter(grounded: true))->bodies(),
        );

        /*
         * ⚠ A QUESTION IS IN NEITHER, AND THIS IS THE ASSERTION THAT FOUND A REAL BUG. The fake
         * cast the column to bool, so a null `grounded` looked like `false` and a person's own
         * question came back as an ungrounded REPLY - which would have handed a product mining
         * its backlog every question alongside every failed answer. The service compares in SQL,
         * where `grounded = 0` is NULL for a null column, so it never did this. A fake more
         * permissive than the service is worse than no fake at all.
         */
        $this->assertCount(3, $conversations->turns()->turns);
    }

    public function test_the_fake_pages_the_corpus_and_refuses_a_cursor_it_does_not_know(): void
    {
        $ai = $this->fake();
        $conversations = $ai->conversations();

        $thread = $conversations->start(ownerRef: 'user-1', audience: 'consultant');

        for ($i = 1; $i <= 5; $i++) {
            $conversations->append($thread->id, ConversationTurn::ROLE_USER, "Question {$i}",
                occurredAt: new DateTimeImmutable('2026-10-01T09:00:0' . $i . '+00:00'));
        }

        $seen = [];

        foreach ($conversations->eachTurn(perPage: 2) as $turn) {
            $seen[] = $turn->id;
        }

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown_cursor/');

        $conversations->turns(before: 'turn-does-not-exist');
    }

    public function test_the_fakes_aggregate_counts_every_reply_per_audience(): void
    {
        $ai = $this->fake();
        $conversations = $ai->conversations();

        $consultant = $conversations->start(ownerRef: 'user-1', audience: 'consultant');
        $client = $conversations->start(ownerRef: 'user-2', audience: 'client');

        $conversations->append($consultant->id, ConversationTurn::ROLE_ASSISTANT, 'Grounded',
            grounded: true, provenanceClass: 'case_record');
        $conversations->append($consultant->id, ConversationTurn::ROLE_ASSISTANT, 'Said nothing');
        $conversations->append($client->id, ConversationTurn::ROLE_ASSISTANT, 'Invented',
            grounded: false, provenanceClass: 'unknown');

        $stats = $conversations->stats();

        $this->assertSame(2, $stats['turns']['by_audience']['consultant']['replies']);
        $this->assertSame(1, $stats['turns']['by_audience']['consultant']['conversations']);

        // The grounding block still divides by the one reply that stated a provenance.
        $this->assertSame(1, $stats['turns']['grounding_by_audience']['consultant']['replies_stating_provenance']);
        $this->assertSame(1, $stats['turns']['by_audience']['client']['replies']);
    }

    public function test_the_fakes_append_carries_the_summary_so_an_index_can_come_from_the_service(): void
    {
        $ai = $this->fake();
        $conversations = $ai->conversations();

        $thread = $conversations->start(ownerRef: 'user-1', audience: 'consultant');

        $turn = $conversations->append($thread->id, ConversationTurn::ROLE_USER, 'A question',
            occurredAt: new DateTimeImmutable('2026-10-01T09:00:00+00:00'));

        $this->assertNotNull($turn->conversation);
        $this->assertSame(1, $turn->conversation->turnCount);
        $this->assertSame(
            '2026-10-01T09:00:00+00:00',
            $turn->conversation->lastTurnAt?->format(DATE_ATOM),
        );
    }
}
