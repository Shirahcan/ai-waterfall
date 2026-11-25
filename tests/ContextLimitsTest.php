<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\FakeAiAdmin;

/** Context limits (2026-11-25): an admin sets how much the model reads per turn; a product reads its own. */
class ContextLimitsTest extends TestCase
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

        return new AiClient(baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']), adminKey: 'admin-key');
    }

    private function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_an_admin_sets_and_resets_a_limit_on_the_wire(): void
    {
        $row = ['product' => 'portify', 'limit' => 'chat.thread', 'tokens' => 60000, 'configured' => true];
        $client = $this->client([$this->json(['context_limit' => $row, 'previous_tokens' => null]), $this->json(['context_limit' => [...$row, 'configured' => false], 'previous_tokens' => 60000])]);

        $set = $client->admin()->setContextLimit('portify', 'chat.thread', 60000);
        $this->assertSame('api/v1/admin/context-limits', ltrim($this->sent[0]->getUri()->getPath(), '/'));
        $this->assertSame('PUT', $this->sent[0]->getMethod());
        $this->assertSame(['product' => 'portify', 'limit' => 'chat.thread', 'tokens' => 60000], json_decode((string) $this->sent[0]->getBody(), true));
        $this->assertNull($set['previous_tokens']);

        $reset = $client->admin()->resetContextLimit('portify', 'chat.thread');
        $this->assertSame('api/v1/admin/context-limits/reset', ltrim($this->sent[1]->getUri()->getPath(), '/'));
        $this->assertSame(60000, $reset['previous_tokens']);
    }

    public function test_a_product_reads_its_own_limits_keyed(): void
    {
        $client = $this->client([$this->json(['context_limits' => [['product' => 'portify', 'limit' => 'console.context', 'tokens' => 64000, 'default_tokens' => 48000, 'configured' => true]]])]);

        $this->assertSame(['console.context' => ['tokens' => 64000, 'default_tokens' => 48000, 'configured' => true]], $client->contextLimits());
        $this->assertSame('api/v1/context-limits', ltrim($this->sent[0]->getUri()->getPath(), '/'));
    }

    public function test_the_fake_lists_every_product_and_says_what_is_undecided(): void
    {
        $fake = new FakeAiAdmin(['portify', 'mploynow']);
        $fake->setContextLimit('portify', 'console.context', 64000);

        $rows = collect_rows($fake->contextLimits()['context_limits']);
        $this->assertTrue($rows['portify/console.context']['configured']);
        $this->assertFalse($rows['mploynow/console.context']['configured']);
        $this->assertSame(48000, $rows['mploynow/console.context']['tokens']);
    }
}

/** @return array<string, array<string, mixed>> */
function collect_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r['product'].'/'.$r['limit']] = $r;
    }

    return $out;
}
