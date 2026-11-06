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
use Shirahcan\AiClient\FakeAiConversations;

/** Who asked and how, for any window (1.45.0; Portify plan porter-stats-reach 04d). */
class ConversationUsageTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    private function client(Response ...$responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $handler) => function (Request $request, array $options) use ($handler) {
            $this->sent[] = $request;

            return $handler($request, $options);
        });

        return new AiClient(baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    public function test_the_turn_carries_how_it_was_asked_and_stats_take_any_window(): void
    {
        $ai = $this->client(
            new Response(201, ['Content-Type' => 'application/json'], json_encode(['turn' => ['id' => 't1', 'role' => 'user', 'body' => 'q', 'created_at' => '2026-11-06T18:00:00+00:00'], 'conversation' => null])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['turns' => ['by_owner' => []]])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['tools' => []])),
        );

        $ai->conversations()->append('c1', 'user', 'q', how: ['surface' => 'panel', 'input' => 'dictated', 'page' => '/admin/cases/{id}']);
        $ai->conversations()->statsFor(new DateTimeImmutable('2026-11-06T17:35:00+00:00'), new DateTimeImmutable('2026-11-06T18:00:00+00:00'));
        $ai->conversations()->toolUsage(new DateTimeImmutable('2026-11-06T17:35:00+00:00'), audience: 'admin');

        $this->assertSame(['role' => 'user', 'body' => 'q', 'surface' => 'panel', 'input' => 'dictated', 'page' => '/admin/cases/{id}'],
            json_decode((string) $this->sent[0]->getBody(), true));
        parse_str($this->sent[1]->getUri()->getQuery(), $q);
        $this->assertSame(['since' => '2026-11-06T17:35:00+00:00', 'until' => '2026-11-06T18:00:00+00:00'], $q);
        $this->assertStringContainsString('conversations/tool-usage', (string) $this->sent[2]->getUri());
    }

    public function test_the_fake_counts_who_and_how_and_refuses_what_the_service_refuses(): void
    {
        $fake = new FakeAiConversations();
        $thread = $fake->start('u-ivie', 'consultant');
        $fake->append($thread->id, 'user', 'q1', how: ['surface' => 'panel', 'input' => 'dictated']);
        $fake->append($thread->id, 'assistant', 'a1', grounded: true);
        $fake->append($thread->id, 'user', 'q2');

        $stats = $fake->statsFor(new DateTimeImmutable('-1 hour'), new DateTimeImmutable('+1 minute'));

        $this->assertSame(2, $stats['turns']['by_owner'][0]['questions']);
        $this->assertSame(1.0, $stats['turns']['by_owner'][0]['grounded_rate']);
        $this->assertSame(['dictated' => 1, 'unrecorded' => 1], $stats['turns']['by_input']);

        $this->expectException(RuntimeException::class);
        $fake->append($thread->id, 'user', 'q3', how: ['page' => '/admin/cases/01a1a377-6910-7392-b426-ed66f154f4fa']);
    }
}
