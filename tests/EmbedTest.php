<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiEmbedding;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Shirahcan\AiClient\FakeAiClient;

class EmbedTest extends TestCase
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

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_it_posts_to_the_embed_endpoint_and_returns_the_vector_with_its_model(): void
    {
        $e = $this->client($this->json(200, [
            'vector' => [0.25, -0.5], 'model' => 'gemini-embedding-001', 'provider' => 'gemini',
            'latency_ms' => 80, 'attempts' => [['provider' => 'gemini']],
        ]))->embed('knowledge.embed.query', 'how do I pay?');

        $this->assertSame([0.25, -0.5], $e->vector);
        $this->assertSame('gemini-embedding-001', $e->model);
        $this->assertSame(2, $e->dimensions());

        $request = $this->sent[0]['request'];
        $this->assertSame('/api/v1/embed', $request->getUri()->getPath());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['task' => 'knowledge.embed.query', 'input' => 'how do I pay?'],
            json_decode((string) $request->getBody(), true),
            'the client must not send a model: the service owns that choice',
        );
    }

    public function test_a_refusal_maps_to_the_same_exceptions_as_generation(): void
    {
        $this->expectException(AllProvidersFailedException::class);
        $this->client($this->json(502, ['error' => 'all_providers_failed', 'attempts' => []]))
            ->embed('t', 'x');
    }

    public function test_an_unknown_error_is_an_unavailable_service(): void
    {
        $this->expectException(AiUnavailableException::class);
        $this->client($this->json(503, ['error' => 'no_credential']))->embed('t', 'x');
    }

    public function test_the_fake_serves_a_queued_vector(): void
    {
        $fake = (new FakeAiClient())->queue([1, 0]);

        $e = $fake->embed('knowledge.embed.document', 'abc');

        $this->assertInstanceOf(AiEmbedding::class, $e);
        $this->assertSame([1.0, 0.0], $e->vector);
        $this->assertSame('embed', $fake->calls[0]['mode']);
        $this->assertSame(3, $fake->calls[0]['payload']['chars']);
    }
}
