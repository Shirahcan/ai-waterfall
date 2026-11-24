<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Chat\ToolStages;
use Shirahcan\AiClient\Laravel\Atlas\DataTools;

/** Plan close-multi-pass-gaps-2026-11-24 P5b: shared tools' status labels live once, in the kit. */
class ToolStagesTest extends TestCase
{
    public function test_every_kit_data_tool_has_a_label(): void
    {
        foreach (DataTools::NAMES as $tool) {
            $this->assertArrayHasKey($tool, ToolStages::DEFAULTS, "{$tool} has no status line");
        }
    }

    public function test_a_products_own_label_wins_and_an_unknown_tool_falls_back(): void
    {
        $labels = ToolStages::merge(['propose_action' => 'Preparing that for you', 'search_records' => 'Finding your record']);

        $this->assertSame('Preparing that for you', ToolStages::label('propose_action', $labels));
        $this->assertSame('Finding your record', ToolStages::label('search_records', $labels));
        $this->assertSame('Checking my answer', ToolStages::label('check_answer', $labels));
        $this->assertSame(ToolStages::FALLBACK, ToolStages::label('something_new', $labels));
    }

    public function test_the_assistant_speaks_in_the_first_person(): void
    {
        foreach (ToolStages::DEFAULTS as $tool => $label) {
            $this->assertDoesNotMatchRegularExpression('/\b(Porter|the assistant|it is)\b/i', $label, $tool);
        }
    }
}
