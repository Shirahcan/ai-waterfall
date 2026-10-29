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
use Shirahcan\AiClient\FakeAiClient;

/**
 * Named task routes (1.25.0): a product names WHICH ladder, the vault console
 * decides which models stand on it.
 *
 * Added 2026-10-29, when moving Portify's verdict tiers to Claude meant editing
 * model ids in Portify's own code. These assert the REQUEST, because a field
 * that never reaches the wire is the defect shape `preferred_models` shipped.
 */
class TaskRoutesTest extends TestCase
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
            adminKey: 'admin-key',
        );
    }

    private function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true) ?: [];
    }

    private function path(int $i = 0): string
    {
        return ltrim($this->sent[$i]->getUri()->getPath(), '/');
    }

    public function test_the_route_key_reaches_the_wire(): void
    {
        $this->client([$this->json(['result' => ['ok' => true], 'provider' => 'claude', 'model' => 'claude-sonnet-5-5'])])
            ->withRoute('assess.case.outcome:deep')
            ->generateJson('assess.case.outcome', 'sys', 'go');

        $this->assertSame('assess.case.outcome:deep', $this->body()['route_key'] ?? null);
    }

    public function test_no_route_sends_no_field_and_does_not_leak(): void
    {
        $client = $this->client([
            $this->json(['result' => ['ok' => true], 'provider' => 'x', 'model' => 'y']),
            $this->json(['result' => ['ok' => true], 'provider' => 'x', 'model' => 'y']),
        ]);

        $client->withRoute('assess.case.outcome:deep')->generateJson('assess.case.outcome', 's', 'u');
        $client->generateJson('porter.classify', 's', 'u');

        $this->assertArrayNotHasKey('route_key', $this->body(1),
            'the client is a singleton, so one call\'s route must not land on the next');
    }

    public function test_setting_a_route_puts_the_whole_ladder(): void
    {
        $this->client([$this->json(['route' => ['route_key' => 'query.agent', 'configured' => true]])])
            ->admin()->setTaskRoute('query.agent', [['provider' => 'claude', 'model' => 'claude-sonnet-5-5']], 'Jane');

        $this->assertSame('PUT', $this->sent[0]->getMethod());
        $this->assertSame('api/v1/admin/task-routes', $this->path());
        $this->assertSame([['provider' => 'claude', 'model' => 'claude-sonnet-5-5']], $this->body()['steps']);
        $this->assertSame('Jane', $this->body()['set_by']);
        $this->assertSame('Bearer admin-key', $this->sent[0]->getHeaderLine('Authorization'));
    }

    public function test_reset_posts_the_key(): void
    {
        $result = $this->client([$this->json(['route' => ['route_key' => 'query.agent'], 'removed' => 2])])
            ->admin()->resetTaskRoute('query.agent');

        $this->assertSame('api/v1/admin/task-routes/reset', $this->path());
        $this->assertSame('query.agent', $this->body()['route_key']);
        $this->assertSame(2, $result['removed']);
    }

    public function test_the_fakes_record_and_resolve_routes(): void
    {
        $fake = new FakeAiClient();
        $fake->queue(['ok' => true]);
        $fake->withRoute('assess.case.outcome:base')->generateJson('assess.case.outcome', 's', 'u');
        $this->assertSame('assess.case.outcome:base', $fake->log->routeKey);

        $admin = new FakeAiAdmin();
        $admin->routeDefaults = ['query.agent' => [['provider' => 'gemini', 'model' => 'gemini-2.5-flash']]];

        $this->assertFalse($admin->taskRoutes()['routes'][0]['configured']);

        $admin->setTaskRoute('query.agent', [['provider' => 'claude', 'model' => 'claude-sonnet-5-5']]);
        $this->assertTrue($admin->taskRoutes()['routes'][0]['configured']);
        $this->assertSame('claude-sonnet-5-5', $admin->taskRoutes()['routes'][0]['steps'][0]['model']);

        $this->assertSame(1, $admin->resetTaskRoute('query.agent')['removed']);
        $this->assertSame('gemini-2.5-flash', $admin->taskRoutes()['routes'][0]['steps'][0]['model']);
    }
}
