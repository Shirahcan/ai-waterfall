<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\ConversationTurn;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\FakeAiClient;

/**
 * Pinning and searching a person's threads (1.46, chat-time-and-threads 01b/01c, owner 2026-11-06),
 * through the client and through the fake. The fake must refuse what the service refuses and return
 * the same keys, or a product's tests vouch for behaviour production rejects (the 1.45.2 lesson).
 */
class ConversationThreadsTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_search_sends_the_owner_and_words_and_reads_the_hits(): void
    {
        $ai = $this->client([$this->json([
            'hits' => [[
                'conversation' => ['id' => 'c-1', 'owner_ref' => 'u1', 'title' => 'Fax', 'pinned_at' => '2026-11-06T10:00:00+00:00'],
                'match' => 'turn', 'snippet' => '...the Résumé for Maria', 'turn_at' => '2026-11-01T10:00:00+00:00',
            ]],
            'scanned' => 3, 'capped' => false,
        ])]);

        $found = $ai->conversations()->search('u1', 'resume', 5);

        parse_str($this->sent[0]->getUri()->getQuery(), $query);
        $this->assertSame('/api/v1/conversations/search', $this->sent[0]->getUri()->getPath());
        $this->assertSame(['owner_ref' => 'u1', 'q' => 'resume', 'limit' => '5'], $query);
        $this->assertSame('c-1', $found['hits'][0]['conversation']->id);
        $this->assertSame('2026-11-06T10:00:00+00:00', $found['hits'][0]['conversation']->pinnedAt?->format(DATE_ATOM));
        $this->assertSame('turn', $found['hits'][0]['match']);
        $this->assertSame('2026-11-01T10:00:00+00:00', $found['hits'][0]['turnAt']?->format(DATE_ATOM));
        $this->assertSame(3, $found['scanned']);
        $this->assertFalse($found['capped']);
    }

    public function test_pinned_rides_the_list_and_the_update(): void
    {
        $ai = $this->client([
            $this->json(['conversations' => []]),
            $this->json(['conversation' => ['id' => 'c-1', 'owner_ref' => 'u1', 'pinned_at' => '2026-11-06T10:00:00+00:00']]),
        ]);

        $ai->conversations()->forOwner('u1', pinned: true);
        $thread = $ai->conversations()->update('c-1', ['pinned' => true, 'owner_ref' => 'someone-else']);

        parse_str($this->sent[0]->getUri()->getQuery(), $query);
        $this->assertSame('1', $query['pinned']);
        $this->assertSame(['pinned' => true], json_decode((string) $this->sent[1]->getBody(), true), 'owner_ref is never editable');
        $this->assertNotNull($thread->pinnedAt);
    }

    public function test_an_eleventh_pin_is_a_refusal_the_person_can_read(): void
    {
        $ai = $this->client([$this->json(['error' => 'pin_limit', 'message' => 'You can pin up to 10 conversations. Unpin one first.'], 422)]);

        try {
            $ai->conversations()->update('c-1', ['pinned' => true]);
            $this->fail('expected a refusal');
        } catch (AiRequestRefusedException $e) {
            $this->assertSame('pin_limit', $e->errorCode);
            $this->assertSame('You can pin up to 10 conversations. Unpin one first.', $e->getMessage());
        }
    }

    public function test_the_fake_pins_lists_and_searches_like_the_service(): void
    {
        $store = (new FakeAiClient())->conversations();
        $fax = $store->start('u1', title: 'Physician fax');
        $store->append($fax->id, ConversationTurn::ROLE_USER, 'Where do I send the Résumé?');
        $other = $store->start('u1', title: 'Résumé checklist');
        $store->start('u2', title: 'Résumé for someone else');

        $this->assertNotNull($store->update($fax->id, ['pinned' => true])->pinnedAt);
        $this->assertSame([$fax->id], array_map(fn ($c) => $c->id, $store->forOwner('u1', pinned: true)));
        $this->assertSame([$other->id], array_map(fn ($c) => $c->id, $store->forOwner('u1', pinned: false)));

        $found = $store->search('u1', 'resume');
        $this->assertEqualsCanonicalizing([$fax->id, $other->id], array_map(fn ($h) => $h['conversation']->id, $found['hits']));
        $this->assertSame(['hits', 'scanned', 'capped'], array_keys($found));
        $this->assertSame(['conversation', 'match', 'snippet', 'turnAt'], array_keys($found['hits'][0]));

        for ($i = 0; $i < 9; $i++) {
            $store->update($store->start('u1', title: "t{$i}")->id, ['pinned' => true]);
        }
        $this->expectException(AiRequestRefusedException::class);
        $store->update($store->start('u1', title: 'one too many')->id, ['pinned' => true]);
    }
}
