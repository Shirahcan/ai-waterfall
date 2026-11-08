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
use Shirahcan\AiClient\Assistant\Surface;
use Shirahcan\AiClient\FakeAiClient;

/**
 * 1.56: the base assistant. A product names its assistant once, a call names its surface, and the
 * service frames the call; a call that names no surface is exactly what it was.
 */
class AssistantSurfaceTest extends TestCase
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

        return (new AiClient(baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/'])))->forAssistant('porter');
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function generation(): Response
    {
        return $this->json(['result' => ['ok' => true], 'provider' => 'gemini', 'model' => 'm', 'latency_ms' => 1,
            'tokens' => ['in' => 1, 'out' => 1], 'attempts' => []]);
    }

    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    private function builder(): Surface
    {
        return new class implements Surface {
            public function key(): string { return 'site_builder'; }

            public function mode(): string { return Surface::GROUNDED; }

            public function label(): string { return 'the site builder'; }

            public function description(): string { return 'You design and write website pages.'; }
        };
    }

    public function test_a_surface_call_sends_the_assistant_block_and_a_plain_call_sends_nothing_new(): void
    {
        $ai = $this->client([$this->generation(), $this->generation()]);

        $ai->runTask('sites.pml.edit', ['ask' => 'x']);
        $this->assertArrayNotHasKey('assistant', $this->body(0));

        $ai->surface($this->builder(), 'admin', 'what Portify does for employers')->runTask('sites.pml.edit', ['ask' => 'x']);
        $this->assertSame(['key' => 'porter', 'surface' => 'site_builder', 'audience' => 'admin', 'mode' => 'grounded',
            'knowledge_query' => 'what Portify does for employers'], $this->body(1)['assistant']);
    }

    public function test_the_block_survives_later_clones_and_never_leaks_back_to_the_singleton(): void
    {
        $ai = $this->client([$this->generation(), $this->generation()]);

        $ai->surface('chat', 'client', null, Surface::CONVERSATION)->withCorrelation(['_key' => 'case:1'])->withBudget(10)
            ->generateJson('t', 's', 'p');
        $this->assertSame(['key' => 'porter', 'surface' => 'chat', 'audience' => 'client', 'mode' => 'conversation'], $this->body(0)['assistant']);

        $ai->generateJson('t', 's', 'p');
        $this->assertArrayNotHasKey('assistant', $this->body(1), 'the singleton is untouched');
    }

    public function test_a_silent_surface_sends_no_block_and_an_unknown_mode_is_refused(): void
    {
        $ai = $this->client([$this->generation()]);
        $ai->surface('intake_read', null, null, Surface::SILENT)->generateJson('t', 's', 'p');
        $this->assertArrayNotHasKey('assistant', $this->body(0));

        $this->expectException(\InvalidArgumentException::class);
        $ai->surface('x', null, null, 'loud');
    }

    public function test_the_record_is_read_by_name_and_a_missing_one_is_null(): void
    {
        $ai = $this->client([$this->json(['assistant' => ['key' => 'porter', 'display_name' => 'Porter']]),
            $this->json(['error' => 'unknown_assistant', 'message' => 'No assistant'], 404)]);

        $this->assertSame('Porter', $ai->assistantRecord()['display_name']);
        $this->assertStringEndsWith('api/v1/assistants/porter', (string) $this->sent[0]->getUri());
        $this->assertNull($ai->assistantRecord('mira'));
    }

    public function test_the_fake_records_the_surface_through_clones(): void
    {
        $fake = (new FakeAiClient())->forAssistant('porter');
        $fake->queue(['ok' => true]);

        $fake->surface('site_builder', 'admin')->withCorrelation(['_key' => 'site:1'])->runTask('sites.pml.edit', []);

        $this->assertSame('site_builder', $fake->assistantCall()['surface'] ?? null);
        $fake->publishAssistant('porter', ['display_name' => 'Porter']);
        $this->assertSame('Porter', $fake->assistantRecord()['display_name']);
    }
}
