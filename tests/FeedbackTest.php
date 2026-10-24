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
 * Votes on answers, through the client and through the fake (P19a).
 *
 * ⚠ THE FAKE IS TESTED AS HARD AS THE CLIENT. Every product's own test suite will rate answers
 * against the fake; a fake that accepted a vote on a person's own question, or a reason on a
 * thumbs-up, would make those suites evidence for behaviour the service rejects.
 */
class FeedbackTest extends TestCase
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

    public function test_a_vote_goes_to_the_turn_route_with_only_what_was_said(): void
    {
        $ai = $this->client([new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'feedback' => ['id' => 'v-1', 'sentiment' => 'negative'],
        ]))]);

        $vote = $ai->conversations()->rate('c-1', 't-9', 'user-7', 'negative', 'outdated_info', '  ');

        $this->assertSame('v-1', $vote['id']);
        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertSame('/api/v1/conversations/c-1/turns/t-9/feedback', $this->sent[0]->getUri()->getPath());

        $body = json_decode((string) $this->sent[0]->getBody(), true);
        $this->assertSame(['voter_ref' => 'user-7', 'sentiment' => 'negative', 'reason_code' => 'outdated_info'], $body);
    }

    public function test_the_fake_records_a_vote_and_a_changed_mind_is_one_vote(): void
    {
        $fake = (new FakeAiClient())->conversations();
        $thread = $fake->start('owner-1', 'consultant');
        $answer = $fake->append($thread->id, ConversationTurn::ROLE_ASSISTANT, 'An LMIA is an assessment.');

        $fake->rate($thread->id, $answer->id, 'user-7', 'negative', 'wrong_topic');
        $fake->rate($thread->id, $answer->id, 'user-7', 'positive');

        $this->assertCount(1, $fake->votes);
        $this->assertSame('positive', array_values($fake->votes)[0]['sentiment']);
        $this->assertNull(array_values($fake->votes)[0]['reason_code']);
    }

    public function test_the_fake_refuses_a_vote_on_a_persons_own_question(): void
    {
        $fake = (new FakeAiClient())->conversations();
        $thread = $fake->start('owner-1', 'consultant');
        $question = $fake->append($thread->id, ConversationTurn::ROLE_USER, 'What is an LMIA?');

        $this->expectException(RuntimeException::class);
        $fake->rate($thread->id, $question->id, 'user-7', 'positive');
    }

    public function test_the_fake_refuses_a_reason_on_a_thumbs_up_and_an_unknown_code(): void
    {
        $fake = (new FakeAiClient())->conversations();
        $thread = $fake->start('owner-1', 'consultant');
        $answer = $fake->append($thread->id, ConversationTurn::ROLE_ASSISTANT, 'Answer.');

        try {
            $fake->rate($thread->id, $answer->id, 'u', 'positive', 'wrong_topic');
            $this->fail('A reason on a thumbs-up was accepted.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $fake->rate($thread->id, $answer->id, 'u', 'negative', 'made_up');
    }

    public function test_erasing_a_thread_in_the_fake_takes_its_votes(): void
    {
        $fake = (new FakeAiClient())->conversations();
        $thread = $fake->start('owner-1', 'consultant');
        $answer = $fake->append($thread->id, ConversationTurn::ROLE_ASSISTANT, 'Answer.');
        $fake->rate($thread->id, $answer->id, 'u', 'negative');

        $fake->eraseForOwner('owner-1');

        $this->assertSame([], $fake->votes);
    }

    public function test_the_fake_groups_votes_by_audience(): void
    {
        $fake = (new FakeAiClient())->conversations();
        $a = $fake->start('o', 'consultant');
        $b = $fake->start('o', 'client');
        $fake->rate($a->id, $fake->append($a->id, ConversationTurn::ROLE_ASSISTANT, 'x')->id, '1', 'negative', 'missing_detail');
        $fake->rate($b->id, $fake->append($b->id, ConversationTurn::ROLE_ASSISTANT, 'y')->id, '2', 'positive');

        $rows = $fake->feedbackStats();

        $this->assertCount(2, $rows);
        $this->assertSame(2, array_sum(array_column($rows, 'votes')));
        $this->assertSame('x', $fake->feedback('negative')[0]['answer']);
    }
}
