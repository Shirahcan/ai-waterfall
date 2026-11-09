<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiResult;
use Shirahcan\AiClient\AiStreamEvent;
use Shirahcan\AiClient\FakeAiClient;

/**
 * A call's level, effort and output size (1.59.0): "pro and expert should fall back to lower levels", and
 * "make the output length/size configurable per call - some of MployNow's Mira reads are being cut off".
 *
 * ⚠ THESE TESTS ASSERT THE REQUEST BODY: a field that never crosses the wire is the defect shape this kit
 * has met before (`preferred_models`), and only the sent request can show it.
 */
class RunTuningTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    private function client(): AiClient
    {
        $ok = fn () => new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'result' => 'words', 'provider' => 'claude', 'model' => 'claude-sonnet-5-5', 'level' => 'pro', 'cut_off' => true,
        ]));
        $stack = HandlerStack::create(new MockHandler([$ok(), $ok(), $ok()]));
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new AiClient(baseUrl: 'http://127.0.0.1:8007', trustKey: 'k', http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        return json_decode((string) end($this->sent)->getBody(), true) ?: [];
    }

    public function test_level_effort_and_size_reach_the_wire(): void
    {
        $result = $this->client()->withLevel('expert')->withEffort('high')->withMaxOutput(12000)->runTask('mira.read', ['x' => 1]);

        $body = $this->lastBody();
        $this->assertSame(['expert', 'high', 12000], [$body['level'] ?? null, $body['effort'] ?? null, $body['max_output_tokens'] ?? null]);
        $this->assertInstanceOf(AiResult::class, $result);
        $this->assertSame('pro', $result->level, 'the level that answered');
        $this->assertTrue($result->cutOff, 'a cut answer says so');
    }

    public function test_a_call_that_asks_for_nothing_sends_nothing_and_a_clone_never_leaks(): void
    {
        $client = $this->client();
        $client->withLevel('expert')->withEffort('high')->withMaxOutput(9000);

        $client->runTask('mira.read', ['x' => 1]);

        $body = $this->lastBody();
        foreach (['level', 'effort', 'max_output_tokens'] as $field) {
            $this->assertArrayNotHasKey($field, $body, "{$field} stays on the clone that asked");
        }
    }

    public function test_normal_and_unknown_values_are_not_sent_and_sizes_are_bounded(): void
    {
        $this->client()->withLevel('normal')->withEffort('max')->withMaxOutput(10)->runTask('mira.read', []);

        $body = $this->lastBody();
        $this->assertArrayNotHasKey('level', $body, 'normal is the ordinary ladder: nothing to ask');
        $this->assertArrayNotHasKey('effort', $body);
        $this->assertSame(256, $body['max_output_tokens']);
    }

    public function test_a_done_event_names_the_level_and_a_cut(): void
    {
        $done = new AiStreamEvent(AiStreamEvent::DONE, ['level' => 'pro', 'cut_off' => true]);
        $token = new AiStreamEvent(AiStreamEvent::TOKEN, ['text' => 'x', 'level' => 'pro']);

        $this->assertSame('pro', $done->servedLevel());
        $this->assertTrue($done->cutOff());
        $this->assertNull($token->servedLevel(), 'only a done event says who answered');
        $this->assertFalse((new AiStreamEvent(AiStreamEvent::DONE, []))->cutOff());
    }

    public function test_the_fake_records_what_was_asked(): void
    {
        $fake = new FakeAiClient;
        $fake->withLevel('pro')->withEffort('medium')->withMaxOutput(4000);

        $this->assertSame(['pro', 'medium', 4000], [$fake->log->level, $fake->log->effort, $fake->log->maxOutputTokens]);
    }
}
