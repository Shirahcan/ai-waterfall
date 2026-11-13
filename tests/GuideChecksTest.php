<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Assistant\GuideChecks;
use Shirahcan\AiClient\Assistant\TemplateMerge;

/**
 * A guide's checks in the kit (1.68.0, shared console C2a).
 *
 * ⚠ WHAT THIS GUARDS: a check runs only when the guide sets it; a template's length names the template; a
 * required section with no heading words is guidance and never a miss; heading words match case-blind; the
 * repair ask carries every miss and says to keep everything else.
 */
class GuideChecksTest extends TestCase
{
    public function test_checks_run_only_when_the_guide_sets_them(): void
    {
        $this->assertSame([], GuideChecks::judge(['checks' => []], 5, false, []));
        $this->assertSame([], GuideChecks::judge(['checks' => ['require_cta' => false, 'min_words' => 0]], 5, false, []));
    }

    public function test_length_and_action_misses_in_plain_words(): void
    {
        $misses = GuideChecks::judge(['checks' => ['min_words' => 300, 'require_cta' => true]], 120, false, []);

        $this->assertSame([
            'It has about 120 words; the guide asks for at least 300.',
            'It has no call to action (a button, a form or a booking link).',
        ], $misses);

        $merged = TemplateMerge::apply(['checks' => ['max_words' => 900]], ['key' => 'quick', 'name' => 'Quick answer', 'checks' => ['max_words' => 400]]);
        $this->assertSame(['It has about 500 words; the Quick answer template asks for at most 400.'], GuideChecks::judge($merged, 500, true, []));
    }

    public function test_a_required_section_is_found_by_its_heading_words_and_one_without_words_is_guidance(): void
    {
        $guide = ['checks' => ['require_sections' => true], 'sections' => [
            ['name' => 'Questions', 'required' => true, 'look_for' => ['FAQ', 'questions']],
            ['name' => 'Next steps', 'required' => true, 'look_for' => ['next step']],
            ['name' => 'The opening', 'required' => true],
            ['name' => 'Sources', 'required' => false, 'look_for' => ['sources']],
        ]];

        $this->assertSame(
            ['The "Next steps" section is missing (no heading with "next step").'],
            GuideChecks::judge($guide, 500, true, ['Common QUESTIONS', 'Who decides'])
        );
    }

    public function test_words_set_markup_aside_and_the_repair_ask_carries_every_miss(): void
    {
        $this->assertSame(['Book', 'a', 'call', '2026'], GuideChecks::words('<p>**Book** a _call_ - 2026</p>'));
        $this->assertSame('The page does not yet meet its guide. Fix only this, keep everything else: A. B.', GuideChecks::repairAsk(['A.', 'B.']));
        $this->assertStringStartsWith('The email does not', GuideChecks::repairAsk(['A.'], 'email'));
    }
}
