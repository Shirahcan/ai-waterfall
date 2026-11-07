<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\FakeAiClient;

/**
 * A clone made by `with*()` shares the fake's queues and records (2026-11-08).
 *
 * ⚠ WHY IT EXISTS. Portify runs every template through `withCorrelation()`, a
 * clone. Each run took the first queued result from the clone's own copy, so a
 * test queueing two results for two calls got the first one twice, and a page
 * writer was handed the answer meant for the step before it.
 */
class FakeCloneSharesStateTest extends TestCase
{
    public function test_each_clone_takes_the_next_result_and_the_original_moves_on(): void
    {
        $fake = (new FakeAiClient())->queue(['step' => 'needs'])->queue(['step' => 'page']);

        $this->assertSame(['step' => 'needs'], $fake->withCorrelation(['case_id' => 1])->runTask('a', [])->data);
        $this->assertSame(['step' => 'page'], $fake->withPersona('porter')->runTask('b', [])->data);

        $this->expectException(AiUnavailableException::class);
        $fake->runTask('c', []);
    }

    public function test_a_clone_queues_and_records_into_the_same_fake(): void
    {
        $fake = new FakeAiClient();
        $fake->withRoute('deep')->queue(['from' => 'the clone']);
        $fake->withCorrelation(['case_id' => 2])->queueSearch([['url' => 'https://example.com', 'title' => 'x', 'content' => 'y']]);

        $this->assertSame(['from' => 'the clone'], $fake->runTask('a', [])->data);
        $this->assertNotNull($fake->withCorrelation(['case_id' => 3])->research()->search('porter.research', 'anything'));
        $this->assertCount(1, $fake->log->calls);
    }

    public function test_a_render_queued_on_the_original_is_seen_by_a_clone(): void
    {
        $fake = (new FakeAiClient())->queueRender('https://example.com', ['html' => '<p>x</p>', 'text' => 'x']);

        $this->assertSame('x', $fake->withCorrelation(['case_id' => 4])->research()->render('https://example.com')['text']);
    }
}
