<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Conversations\OwnedThreads;

/** Service-only threads: ownership is enforced on every read and write, and the owner is a hash. */
class OwnedThreadsTest extends TestCase
{
    private FakeAiClient $ai;

    private OwnedThreads $threads;

    protected function setUp(): void
    {
        $this->ai = new FakeAiClient();
        $this->threads = new OwnedThreads($this->ai, 'operator', personaKey: 'reeve');
    }

    public function test_the_owner_ref_is_a_stable_hash_and_never_the_identifier(): void
    {
        $a = OwnedThreads::ownerRef('operator', ' Ana@Books.ca ');
        $b = OwnedThreads::ownerRef('operator', 'ana@books.ca');

        $this->assertSame($a, $b);
        $this->assertStringStartsWith('operator:', $a);
        $this->assertStringNotContainsString('ana', $a);
    }

    public function test_a_recorded_answer_reopens_with_its_structure(): void
    {
        $owner = OwnedThreads::ownerRef('operator', 'ana');
        $ids = $this->threads->record($owner, null, 'What did we spend in March?', '$4,200', ['facts_used' => ['l1']], grounded: true);

        $thread = $this->threads->read($owner, $ids['conversation_id']);

        $this->assertCount(2, $thread['messages']);
        $this->assertSame('What did we spend in March?', $thread['messages'][0]['content']);
        $this->assertSame(['facts_used' => ['l1']], $thread['messages'][1]['structured']);
        $this->assertSame('What did we spend in March?', $this->threads->list($owner)[0]['title']);
    }

    public function test_another_owners_thread_is_absent_on_every_path(): void
    {
        $ana = OwnedThreads::ownerRef('operator', 'ana');
        $bo = OwnedThreads::ownerRef('operator', 'bo');
        $ids = $this->threads->record($ana, null, 'Q', 'A');

        $this->assertNull($this->threads->read($bo, $ids['conversation_id']));
        $this->assertFalse($this->threads->erase($bo, $ids['conversation_id']));
        $this->assertFalse($this->threads->rate($bo, $ids['conversation_id'], $ids['turn_id'], 'positive'));
        $this->assertSame([], $this->threads->list($bo));

        // Naming someone else's thread starts a fresh one rather than writing into theirs.
        $mine = $this->threads->record($bo, $ids['conversation_id'], 'Q2', 'A2');
        $this->assertNotSame($ids['conversation_id'], $mine['conversation_id']);
    }

    public function test_the_owner_can_continue_rate_and_erase_their_thread(): void
    {
        $owner = OwnedThreads::ownerRef('operator', 'ana');
        $first = $this->threads->record($owner, null, 'Q1', 'A1');
        $second = $this->threads->record($owner, $first['conversation_id'], 'Q2', 'A2');

        $this->assertSame($first['conversation_id'], $second['conversation_id']);
        $this->assertCount(4, $this->threads->read($owner, $first['conversation_id'])['messages']);
        $this->assertTrue($this->threads->rate($owner, $first['conversation_id'], $second['turn_id'], 'positive'));
        $this->assertTrue($this->threads->erase($owner, $first['conversation_id']));
        $this->assertNull($this->threads->read($owner, $first['conversation_id']));
    }
}
