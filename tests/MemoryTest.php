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
use Shirahcan\AiClient\FakeAiClient;

/**
 * Memory, through the client and through the fake (P19e).
 */
class MemoryTest extends TestCase
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

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_a_put_sends_the_owner_value_and_source(): void
    {
        $ai = $this->client([$this->json(['memory' => []], 201)]);

        $ai->memory()->put('user:7', 'family.principal', ['value' => 'my wife'], 'inferred');

        $this->assertSame('PUT', $this->sent[0]->getMethod());
        $this->assertSame('/api/v1/memory/family.principal', $this->sent[0]->getUri()->getPath());
        $this->assertSame(
            ['owner_ref' => 'user:7', 'value' => ['value' => 'my wife'], 'source' => 'inferred'],
            json_decode((string) $this->sent[0]->getBody(), true),
        );
    }

    public function test_all_is_keyed_by_memory_key(): void
    {
        $ai = $this->client([$this->json(['memory' => [
            ['key' => 'a', 'value' => 1, 'source' => 'explicit', 'updated_at' => null],
        ]])]);

        $this->assertSame(1, $ai->memory()->all('user:7')['a']['value']);
        $this->assertSame('owner_ref=user%3A7', $this->sent[0]->getUri()->getQuery());
    }

    public function test_clear_refuses_to_run_without_an_owner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->memory()->clear('  ');
    }

    public function test_the_fake_corrects_rather_than_duplicates_and_refuses_a_bad_key(): void
    {
        $memory = (new FakeAiClient())->memory();
        $memory->put('u', 'province', 'Alberta');
        $memory->put('u', 'province', 'British Columbia', 'inferred');

        $this->assertSame(['province'], array_keys($memory->all('u')));
        $this->assertSame('British Columbia', $memory->all('u')['province']['value']);

        $this->expectException(RuntimeException::class);
        $memory->put('u', str_repeat('k', 65), 'x');
    }

    public function test_erasing_a_person_in_the_fake_also_forgets_them(): void
    {
        $ai = new FakeAiClient();
        $ai->memory()->put('user:7', 'a', 'v');
        $ai->conversations()->start('user:7', 'consultant');

        $receipt = $ai->conversations()->eraseForOwner('user:7');

        $this->assertSame(1, $receipt['memories']);
        $this->assertSame([], $ai->memory()->all('user:7'));
    }
}
