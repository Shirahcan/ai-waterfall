<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\FakeAiClient;

/** Research end to end in ai-service (`research()->run()`, 1.33). */
class ResearchRunTest extends TestCase
{
    public function test_run_posts_the_request_and_returns_the_services_answer(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], (string) json_encode(['status' => 'success', 'answer' => 'Ten years.']))]));
        $stack->push(Middleware::history($sent));
        $client = new AiClient('http://127.0.0.1:8007', 'k', 60, new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));

        $out = $client->research()->run(['query' => 'biometrics validity', 'task' => 'research.search', 'template' => 'research.synthesize',
            'include_domains' => [], 'fetch_top' => 2]);

        $this->assertSame('Ten years.', $out['answer']);
        $this->assertSame('/api/v1/research/run', $sent[0]['request']->getUri()->getPath());
        $body = json_decode((string) $sent[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('include_domains', $body, 'empty options are not sent');
        $this->assertSame('research.synthesize', $body['template']);
    }

    public function test_the_fake_serves_queued_runs_and_logs_requests(): void
    {
        $fake = (new FakeAiClient())->queueResearchRun(['status' => 'empty']);

        $this->assertSame('empty', $fake->research()->run(['query' => 'q', 'task' => 't', 'template' => 'x'])['status']);
        $this->assertSame('q', $fake->researchRequests[0]['query']);
    }
}
