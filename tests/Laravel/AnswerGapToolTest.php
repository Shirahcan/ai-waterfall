<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Laravel\Tools\Standard\AnswerGap;
use Shirahcan\AiClient\Laravel\Tools\Standard\ReportAnswerGapTool;
use Shirahcan\AiClient\Laravel\Tools\Standard\StandardToolbox;
use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/** The shared `report_answer_gap`: the model says it fell short, the product reads it off the turn (1.35). */
class AnswerGapToolTest extends TestCase
{
    public function test_it_is_offered_to_every_audience_including_a_visitor(): void
    {
        $box = new StandardToolbox(new ReportAnswerGapTool());

        foreach (['admin', 'consultant', 'client', 'public'] as $audience) {
            $this->assertSame(['report_answer_gap'], array_column($box->definitions($audience), 'name'));
        }
    }

    public function test_a_report_becomes_an_unrendered_gap_card(): void
    {
        $out = (new StandardToolbox(new ReportAnswerGapTool()))->run('report_answer_gap',
            ['coverage' => 'partial', 'missing' => 'users broken down by role'], new ToolActor('u-1', 'admin'));

        $this->assertTrue($out['ok']);
        $this->assertSame(['type' => 'answer_gap', 'payload' => ['coverage' => 'partial', 'missing' => 'users broken down by role']], $out['card']);
    }

    public function test_an_unknown_coverage_reads_as_none_and_missing_is_capped(): void
    {
        $out = (new ReportAnswerGapTool())->run(['coverage' => 'most of it', 'missing' => str_repeat('x', 400)], ToolActor::guest('g'));

        $this->assertSame('none', $out['card']['payload']['coverage']);
        $this->assertSame(300, mb_strlen($out['card']['payload']['missing']));
    }

    public function test_find_and_strip(): void
    {
        $cards = [
            ['type' => 'data_table', 'payload' => ['rows' => []]],
            ['type' => 'answer_gap', 'payload' => ['coverage' => 'partial', 'missing' => 'a']],
            ['type' => 'answer_gap', 'payload' => ['coverage' => 'none', 'missing' => 'b']],
        ];

        $this->assertSame(['coverage' => 'none', 'missing' => 'b'], AnswerGap::find($cards));
        $this->assertSame([['type' => 'data_table', 'payload' => ['rows' => []]]], AnswerGap::strip($cards));
        $this->assertNull(AnswerGap::find([$cards[0]]));
    }
}
