<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Chat\ChatTurnInput;

/** A turn asks for multi-pass answering only when the product says so (plan porter-multi-pass-answering P1c). */
class MultiPassTurnTest extends TestCase
{
    public function test_a_turn_carries_multi_pass_and_its_budget_only_when_the_product_turns_it_on(): void
    {
        $on = (new ChatTurnInput('hi', 'admin', multiPass: true, maxSteps: 8))->toWire();
        $this->assertTrue($on['multi_pass']);
        $this->assertSame(8, $on['max_steps']);

        $off = (new ChatTurnInput('hi', 'admin', maxSteps: 8))->toWire();
        $this->assertArrayNotHasKey('multi_pass', $off);
        $this->assertArrayNotHasKey('max_steps', $off);
    }
}
