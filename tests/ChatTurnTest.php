<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiStreamEvent;
use Shirahcan\AiClient\Chat\ChatTurnInput;
use Shirahcan\AiClient\Chat\ToolRequestVerifier;
use Shirahcan\AiClient\Exceptions\AiOverBudgetException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\FakeAiClient;

/**
 * A chat turn through the package: `chatTurn()` over SSE, and the verifier every
 * product uses on the tool calls the engine makes back.
 */
class ChatTurnTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    private function client(Response ...$responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return new AiClient('http://127.0.0.1:8007', 'test-key', 60,
            new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    private function frame(string $event, array $data): string
    {
        return 'event: '.$event."\n".'data: '.json_encode($data)."\n\n";
    }

    private function input(): ChatTurnInput
    {
        return new ChatTurnInput(message: 'hi', audience: 'admin', pageRoute: '/admin', toolToken: 'tok', memoryRef: 'user:1');
    }

    public function test_a_turn_streams_its_events_and_returns_the_final(): void
    {
        $client = $this->client(new Response(200, [], implode('', [
            $this->frame('open', ['stream_id' => 's1']),
            $this->frame('tool', ['name' => 'search_knowledge', 'status' => 'running']),
            $this->frame('token', ['text' => 'Hel']),
            $this->frame('token', ['text' => 'lo']),
            $this->frame('card', ['type' => 'action_confirm', 'payload' => ['action_id' => 'a']]),
            $this->frame('final', ['text' => 'Hello', 'cards' => [['type' => 'action_confirm', 'payload' => ['action_id' => 'a']]],
                'tool_calls' => [['name' => 'search_knowledge', 'ok' => true]], 'usage' => ['steps' => 2], 'model' => 'claude-haiku-5-5']),
        ])));

        $seen = [];
        $opened = null;
        $result = $client->chatTurn($this->input(), function (AiStreamEvent $e) use (&$seen) {
            $seen[] = $e->type;
        }, function (string $id) use (&$opened) {
            $opened = $id;
        });

        $this->assertSame(['open', 'tool', 'token', 'token', 'card', 'final'], $seen);
        $this->assertSame('s1', $opened);
        $this->assertSame('Hello', $result->text);
        $this->assertSame('action_confirm', $result->cards[0]['type']);
        $this->assertSame(2, $result->usage['steps']);
        $this->assertSame('s1', $result->streamId);

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('api/v1/chat/turns', ltrim($this->sent[0]['request']->getUri()->getPath(), '/'));
        $this->assertSame(['audience' => 'admin', 'memory_ref' => 'user:1'], $body['user']);
        $this->assertSame('tok', $body['tool_token']);
    }

    public function test_an_error_event_or_a_dropped_stream_throws(): void
    {
        $client = $this->client(
            new Response(200, [], $this->frame('open', ['stream_id' => 's1']).$this->frame('error', ['code' => 'all_providers_failed', 'message' => 'down'])),
            new Response(200, [], $this->frame('open', ['stream_id' => 's2']).$this->frame('token', ['text' => 'half'])),
        );

        try {
            $client->chatTurn($this->input());
            $this->fail('an error event must throw');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('down', $e->getMessage());
        }

        $this->expectException(AiUnavailableException::class);
        $client->chatTurn($this->input());
    }

    public function test_a_refusal_before_the_stream_is_the_usual_exception(): void
    {
        $client = $this->client(new Response(429, [], json_encode(['error' => 'over_budget', 'message' => 'cap'])));

        $this->expectException(AiOverBudgetException::class);
        $client->chatTurn($this->input());
    }

    public function test_the_verifier_checks_the_hmac_and_the_clock(): void
    {
        $headers = ToolRequestVerifier::sign('{"input":{}}', 'secret-secret', 1000);

        $this->assertTrue(ToolRequestVerifier::verify($headers['X-Ai-Timestamp'], $headers['X-Ai-Signature'], '{"input":{}}', 'secret-secret', now: 1030));
        $this->assertFalse(ToolRequestVerifier::verify($headers['X-Ai-Timestamp'], $headers['X-Ai-Signature'], '{"input":{"x":1}}', 'secret-secret', now: 1030), 'a changed body');
        $this->assertFalse(ToolRequestVerifier::verify($headers['X-Ai-Timestamp'], $headers['X-Ai-Signature'], '{"input":{}}', 'secret-secret', now: 1100), 'a replay');
        $this->assertFalse(ToolRequestVerifier::verify($headers['X-Ai-Timestamp'], $headers['X-Ai-Signature'], '{"input":{}}', '', now: 1030), 'no secret');
    }

    public function test_a_tool_host_is_registered_with_a_write_only_secret(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['host' => ['product' => 'portify', 'has_secret' => true]]))]));
        $stack->push(Middleware::history($this->sent));
        $client = new AiClient('http://127.0.0.1:8007', 'test-key', 60,
            new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']), adminKey: 'admin-key');

        $host = $client->admin()->setToolHost('portify', 'https://api.portify.test', str_repeat('s', 40), setBy: 'deploy');

        $this->assertTrue($host['has_secret']);
        $request = $this->sent[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(['product' => 'portify', 'base_url' => 'https://api.portify.test', 'signing_secret' => str_repeat('s', 40),
            'set_by' => 'deploy', 'enabled' => true], json_decode((string) $request->getBody(), true));
    }

    public function test_a_deferred_turn_carries_its_tickets_and_continues_to_an_answer(): void
    {
        $client = $this->client(
            new Response(200, [], $this->frame('final', ['text' => 'Working on it.', 'deferred' => [['ticket' => 't-1', 'label' => 'open cases'], ['bad' => 1]]])),
            new Response(200, [], (string) json_encode(['final' => ['text' => 'You have 12.', 'cards' => [['type' => 'data_table', 'payload' => []]]]])),
            new Response(404, [], (string) json_encode(['error' => 'unknown_ticket'])),
        );

        $turn = $client->chatTurn($this->input());
        $this->assertSame([['ticket' => 't-1', 'label' => 'open cases']], $turn->deferred);

        $answer = $client->continueTurn('t-1', ['ok' => true, 'result' => ['answer' => '12']], 'tok-2');
        $this->assertSame('You have 12.', $answer?->text);
        $this->assertSame(['ticket' => 't-1', 'outcome' => ['ok' => true, 'result' => ['answer' => '12']], 'tool_token' => 'tok-2'],
            json_decode((string) $this->sent[1]['request']->getBody(), true));

        $this->assertNull($client->continueTurn('t-1', ['ok' => true]), 'an expired ticket is null, not an exception');
    }

    public function test_channel_events_are_read_with_their_ids_until_idle(): void
    {
        $client = $this->client(new Response(200, [], ": ping\n\n"
            ."id: 3\nevent: turn\ndata: {\"ticket\":\"t-1\",\"message\":{\"id\":9}}\n\n"
            ."event: idle\ndata: {\"after\":3}\n\n"));

        $seen = [];
        $last = $client->chatEvents('conv-1', 2, function (int $id, string $type, array $data) use (&$seen) {
            $seen[] = [$id, $type, $data['message']['id']];
        });

        $this->assertSame([[3, 'turn', 9]], $seen);
        $this->assertSame(3, $last);
        $this->assertStringContainsString('after=2', (string) $this->sent[0]['request']->getUri()->getQuery());
    }

    public function test_the_fake_continues_publishes_and_replays_channel_events(): void
    {
        $fake = (new FakeAiClient())->queueContinue('t-1', 'Done.');

        $this->assertSame('Done.', $fake->continueTurn('t-1', ['ok' => true])?->text);
        $this->assertNull($fake->continueTurn('t-1', ['ok' => true]));

        $fake->publishChatEvent('conv-1', 'turn', ['ticket' => 't-1']);
        $seen = [];
        $fake->chatEvents('conv-1', 0, function (int $id, string $type) use (&$seen) {
            $seen[] = $type;
        });
        $this->assertSame(['turn'], $seen);
    }

    public function test_the_fake_records_the_turn_and_replays_a_queued_answer(): void
    {
        $fake = (new FakeAiClient())->queueChatAnswer('Hi there', [['type' => 'action_launch', 'payload' => ['module_id' => 'm']]]);

        $result = $fake->chatTurn($this->input());

        $this->assertSame('Hi there', $result->text);
        $this->assertSame('action_launch', $result->cards[0]['type']);
        $this->assertSame('chat', $fake->log->calls[0]['mode']);

        $this->expectException(AiUnavailableException::class);
        $fake->chatTurn($this->input());
    }
}
