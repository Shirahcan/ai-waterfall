<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Chat\ChatTurnInput;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Console\ConsoleRoutes;

/**
 * What the assistant could not do (1.40): the turn flag, the triage calls, the fake, and the console
 * rows the shared inbox reaches them by.
 */
class GapsTest extends TestCase
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
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    public function test_a_turn_asks_for_gap_reports_only_when_the_product_says_so(): void
    {
        $this->assertTrue((new ChatTurnInput('hi', 'admin', reportGaps: true))->toWire()['report_gaps']);
        $this->assertArrayNotHasKey('report_gaps', (new ChatTurnInput('hi', 'admin'))->toWire());
    }

    public function test_the_list_and_the_triage_reach_their_routes(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['data' => [['id' => 'g1', 'occurrences' => 3]], 'meta' => ['total' => 1]])),
            new Response(200, [], json_encode(['data' => ['id' => 'g1', 'status' => 'fixed']])),
        ]);

        $page = $client->conversations()->gapsPage(['status' => 'open', 'ignored' => 'x'], 2, 10);
        $updated = $client->conversations()->updateGap('g1', 'fixed', 'shipped', 'Ada');

        $this->assertSame('g1', $page['gaps'][0]['id']);
        $this->assertSame('/api/v1/gaps', $this->sent[0]->getUri()->getPath());
        parse_str($this->sent[0]->getUri()->getQuery(), $q);
        $this->assertSame(['status' => 'open', 'page' => '2', 'per_page' => '10'], $q);
        $this->assertSame(['PATCH', '/api/v1/gaps/g1'], [$this->sent[1]->getMethod(), $this->sent[1]->getUri()->getPath()]);
        $this->assertSame('fixed', $updated['status']);
    }

    public function test_the_fake_filters_ranks_and_triages(): void
    {
        $fake = (new FakeAiClient())->conversations();
        $fake->seedGap(['id' => 'a', 'occurrences' => 1]);
        $fake->seedGap(['id' => 'b', 'occurrences' => 5, 'category' => 'tool_refused']);

        $this->assertSame(['b', 'a'], array_column($fake->gapsPage()['gaps'], 'id'));
        $this->assertSame(['b'], array_column($fake->gapsPage(['category' => 'tool_refused'])['gaps'], 'id'));
        $this->assertSame('planned', $fake->updateGap('a', 'planned')['status']);
        $this->assertSame(1, $fake->gapStats()['planned']);
    }

    public function test_the_console_forwards_the_gap_rows_and_stamps_who_triaged(): void
    {
        $rows = array_values(array_filter(ConsoleRoutes::TABLE, fn ($r) => str_starts_with($r[1], 'gaps')));
        $this->assertSame([['GET', 'gaps'], ['GET', 'gaps/stats'], ['PATCH', 'gaps/{id}']], array_map(fn ($r) => [$r[0], $r[1]], $rows));
        $this->assertSame('by', $rows[2][4]);
    }
}
