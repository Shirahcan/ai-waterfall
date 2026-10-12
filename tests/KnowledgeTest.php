<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiKnowledge;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Shirahcan\AiClient\FakeAiClient;

/**
 * The knowledge serving index, through the client.
 */
class KnowledgeTest extends TestCase
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

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    public function test_sync_posts_the_entries(): void
    {
        $this->client([$this->json(['synced' => 2, 'embedded' => 1, 'skipped' => 1, 'failed' => [], 'model' => 'm'])])
            ->knowledge()->sync([
                ['slug' => 'a', 'text' => 'one', 'audience' => 'admin'],
                ['slug' => 'b', 'text' => 'two'],
            ]);

        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertSame('api/v1/knowledge/sync', ltrim($this->sent[0]->getUri()->getPath(), '/'));
        $this->assertCount(2, $this->body()['entries']);
        $this->assertSame('knowledge.embed.document', $this->body()['task']);
    }

    /**
     * ⚠ THE LIST IS WHAT SHOULD EXIST, NOT WHAT TO DELETE. A "delete these"
     * call silently keeps anything a crashed sync forgot to mention; a
     * keep-list cannot drift.
     */
    public function test_retire_sends_the_slugs_that_should_remain(): void
    {
        $this->client([$this->json(['retired' => 3])])
            ->knowledge()->retire(['a', 'b', 'a']);

        $this->assertSame(['a', 'b'], $this->body()['keep']);
        $this->assertArrayNotHasKey('confirm_empty', $this->body());
    }

    public function test_retire_can_confirm_an_empty_index(): void
    {
        $this->client([$this->json(['retired' => 9])])
            ->knowledge()->retire([], confirmEmpty: true);

        $this->assertSame([], $this->body()['keep']);
        $this->assertTrue($this->body()['confirm_empty']);
    }

    /**
     * ⚠ NULL AUDIENCE IS OMITTED, NOT SENT AS NULL, so the service's own
     * "audience-blind only" reading applies - and the two cases cannot drift
     * apart between the client and the service.
     */
    public function test_retrieve_omits_an_absent_audience(): void
    {
        $this->client([$this->json(['matches' => [], 'model' => 'm', 'scored' => 0, 'skipped_stale_model' => 0, 'index_stale' => false])])
            ->knowledge()->retrieve('a question');

        $body = $this->body();

        $this->assertSame('a question', $body['query']);
        $this->assertArrayNotHasKey('audience', $body);
        $this->assertArrayNotHasKey('floor', $body);
        $this->assertSame(5, $body['limit']);
    }

    public function test_retrieve_sends_the_audience_and_the_floor(): void
    {
        $this->client([$this->json(['matches' => [], 'model' => 'm', 'scored' => 0, 'skipped_stale_model' => 0, 'index_stale' => false])])
            ->knowledge()->retrieve('q', 'consultant', 3, 0.4, budgetSeconds: 2.5);

        $this->assertSame('consultant', $this->body()['audience']);
        $this->assertSame(3, $this->body()['limit']);
        $this->assertEquals(0.4, $this->body()['floor']);
        $this->assertEquals(2.5, $this->body()['budget_seconds']);
    }

    /**
     * ⚠ A FAILED QUERY EMBEDDING THROWS, so a caller falls back to its own
     * lexical path. An empty result would be indistinguishable from "nothing
     * matched", and one of those is a reason to tell the user there is no
     * answer while the other is not.
     */
    public function test_an_unavailable_embedding_throws_rather_than_returning_nothing(): void
    {
        $this->expectException(AllProvidersFailedException::class);

        $this->client([$this->json(['error' => 'embedding_unavailable'], 503)])
            ->knowledge()->retrieve('q');
    }

    /**
     * ⚠ A REFUSED REQUEST IS NOT AN OUTAGE, and the service's own message is
     * the thing worth keeping. This arrived as "ai-service returned an error
     * (HTTP 422)" until 1.3, which sent a reader to check whether the service
     * was up instead of at the sentence saying what to fix.
     */
    public function test_a_refusal_carries_the_services_own_message_and_status(): void
    {
        try {
            $this->client([$this->json([
                'error' => 'refusing_to_empty_the_index',
                'message' => 'No slugs were listed. Pass confirm_empty to retire everything on purpose.',
            ], 422)])->knowledge()->retire(['a']);

            $this->fail('a 422 did not throw');
        } catch (AiRequestRefusedException $e) {
            $this->assertStringContainsString('Pass confirm_empty', $e->getMessage());
            $this->assertSame(422, $e->status);
            $this->assertSame('refusing_to_empty_the_index', $e->errorCode);

            /*
             * ⚠ AND IT IS STILL CATCHABLE AS THE OLD TYPE. Four products catch
             * AiUnavailableException to degrade; a refusal escaping those blocks
             * would be a regression shipped by a release about better reporting.
             */
            $this->assertInstanceOf(AiUnavailableException::class, $e);
        }
    }

    /** A 5xx stays an outage: the remedies are opposite. */
    public function test_a_server_error_is_still_an_outage(): void
    {
        try {
            $this->client([$this->json(['message' => 'boom'], 500)])->knowledge()->status();
            $this->fail('a 500 did not throw');
        } catch (AiUnavailableException $e) {
            $this->assertNotInstanceOf(AiRequestRefusedException::class, $e);
        }
    }

    public function test_status_reads_the_index(): void
    {
        $status = $this->client([$this->json(['chunks' => 12, 'stale_chunks' => 3, 'current_model' => 'm'])])
            ->knowledge()->status();

        $this->assertSame('GET', $this->sent[0]->getMethod());
        $this->assertSame(3, $status['stale_chunks']);
    }

    /*
    |--------------------------------------------------------------------------
    | The fake
    |--------------------------------------------------------------------------
    */

    public function test_the_fake_is_a_real_subclass_and_one_instance(): void
    {
        $fake = new FakeAiClient();

        $this->assertInstanceOf(AiKnowledge::class, $fake->knowledge());
        $this->assertSame($fake->knowledge(), $fake->knowledge());
    }

    public function test_the_fake_syncs_and_retrieves(): void
    {
        $fake = new FakeAiClient();

        $fake->knowledge()->sync([
            ['slug' => 'admin.vault', 'text' => 'where do I manage provider keys', 'audience' => 'admin'],
            ['slug' => 'client.pay', 'text' => 'how do I pay my consultant fee', 'audience' => 'client'],
        ]);

        $found = $fake->knowledge()->retrieve('manage provider keys', 'admin');

        $this->assertSame('admin.vault', $found['matches'][0]['slug']);
        $this->assertFalse($found['index_stale']);
    }

    /**
     * ⚠ THE FILTER IS HARD IN THE FAKE TOO. A double that merely ranked would
     * let a product ship the role-blind defect and pass its tests: a practising
     * consultant told to "ask your consultant".
     */
    public function test_the_fake_never_serves_an_admin_entry_to_a_client(): void
    {
        $fake = new FakeAiClient();

        $fake->knowledge()->sync([
            ['slug' => 'admin.secret', 'text' => 'the admin only thing', 'audience' => 'admin'],
        ]);

        $this->assertSame([], $fake->knowledge()->retrieve('the admin only thing', 'client')['matches']);
        // And an omitted audience is the NARROW reading, not everything.
        $this->assertSame([], $fake->knowledge()->retrieve('the admin only thing')['matches']);
    }

    public function test_the_fake_skips_unchanged_text(): void
    {
        $fake = new FakeAiClient();
        $entries = [['slug' => 'a', 'text' => 'unchanged text here']];

        $this->assertSame(1, $fake->knowledge()->sync($entries)['embedded']);
        $this->assertSame(1, $fake->knowledge()->sync($entries)['skipped']);
    }

    /** ⚠ And a model change re-embeds it, as the service does. */
    public function test_the_fake_re_embeds_after_a_model_change(): void
    {
        $fake = new FakeAiClient();
        $entries = [['slug' => 'a', 'text' => 'unchanged text here']];

        $fake->knowledge()->sync($entries);

        /** @var \Shirahcan\AiClient\FakeAiKnowledge $knowledge */
        $knowledge = $fake->knowledge();
        $knowledge->changeModel('a-newer-model');

        $this->assertSame(1, $fake->knowledge()->sync($entries)['embedded']);
    }

    /**
     * ⚠ A STALE INDEX READS AS STALE, NOT AS EMPTY. Otherwise a product
     * concludes the question had no match and never re-syncs, and semantic
     * retrieval is silently off for as long as nobody looks.
     */
    public function test_the_fake_reports_a_stale_index(): void
    {
        $fake = new FakeAiClient();

        $fake->knowledge()->sync([['slug' => 'a', 'text' => 'something findable']]);

        /** @var \Shirahcan\AiClient\FakeAiKnowledge $knowledge */
        $knowledge = $fake->knowledge();
        $knowledge->changeModel('a-newer-model');

        $result = $fake->knowledge()->retrieve('something findable');

        $this->assertTrue($result['index_stale']);
        $this->assertSame([], $result['matches']);
        $this->assertSame(1, $result['skipped_stale_model']);
        $this->assertSame(1, $fake->knowledge()->status()['stale_chunks']);
    }

    public function test_the_fake_retires_and_refuses_an_unconfirmed_empty_list(): void
    {
        $fake = new FakeAiClient();

        $fake->knowledge()->sync([
            ['slug' => 'keep.me', 'text' => 'one'],
            ['slug' => 'drop.me', 'text' => 'two'],
        ]);

        $this->assertSame(1, $fake->knowledge()->retire(['keep.me']));

        try {
            $fake->knowledge()->retire([]);
            $this->fail('the fake honoured an unconfirmed empty retire and wiped the index');
        } catch (AllProvidersFailedException $e) {
            $this->assertArrayHasKey('refusing_to_empty_the_index', $e->attempts);
        }

        $this->assertSame(1, $fake->knowledge()->status()['chunks']);
    }
}
