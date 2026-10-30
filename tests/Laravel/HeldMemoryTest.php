<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\AiConversations;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Conversations\TurnFeedback;
use Shirahcan\AiClient\Laravel\Memory\HeldMemory;

/** Memory read once per request and fail-soft on read; feedback in the service's one vocabulary. */
class HeldMemoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    public function test_a_write_releases_the_request_copy(): void
    {
        $memory = new HeldMemory(new FakeAiClient());

        $this->assertSame([], $memory->all('user:1'));
        $memory->put('user:1', 'tone', 'brief');

        $this->assertSame('brief', $memory->find('user:1', 'tone')['value'] ?? null);
        $memory->forget('user:1', 'tone');
        $this->assertNull($memory->find('user:1', 'tone'));
    }

    public function test_an_unreachable_service_reads_empty_and_says_so(): void
    {
        $ai = new class extends FakeAiClient {
            public function memory(): \Shirahcan\AiClient\FakeAiMemoryStore
            {
                throw new \Shirahcan\AiClient\Exceptions\AiUnavailableException('down');
            }
        };
        $memory = new HeldMemory($ai);

        $this->assertSame([], $memory->all('user:1'));
        $this->assertTrue($memory->lastReadFailed(), 'a screen must say it could not load, not "no memories"');
    }

    public function test_feedback_reasons_are_the_services_and_a_guest_is_never_stored_in_clear(): void
    {
        $this->assertSame(array_values(AiConversations::FEEDBACK_REASONS), TurnFeedback::reasons());
        $this->assertStringNotContainsString('secret-token', TurnFeedback::guestRef('secret-token'));
        $this->assertSame('user:7', TurnFeedback::userRef('7'));
    }
}
