<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiStreamEvent;
use Shirahcan\AiClient\Chat\ChatTurnInput;

/** A turn asks for thinking only when the surface shows it (Portify's Porter test console, 2026-11-27). */
class ReasoningTurnTest extends TestCase
{
    public function test_a_turn_carries_reasoning_only_when_asked(): void
    {
        $this->assertTrue((new ChatTurnInput('hi', 'admin', reasoning: true))->toWire()['reasoning']);
        $this->assertArrayNotHasKey('reasoning', (new ChatTurnInput('hi', 'admin'))->toWire());
    }

    public function test_a_reasoning_event_reads_its_text(): void
    {
        $this->assertSame('Checking first.', (new AiStreamEvent(AiStreamEvent::REASONING, ['text' => 'Checking first.']))->reasoning());
    }
}
