<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Assistant\TemplateMerge;

/**
 * A guide with a template laid over it (1.68.0, moved from Portify, shared console C2a).
 *
 * ⚠ WHAT THIS GUARDS: the template's sections replace the guide's (blank ones dropped), its instructions are
 * ADDED after the guide's, it overrides only the checks it sets (a null leaves the guide's), its data list
 * replaces the guide's only when it has one, and the template block keeps what the prompts read and drops
 * blanks. Everything else the guide holds (what it drafts) stays the guide's.
 */
class TemplateMergeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function guide(): array
    {
        return [
            'key' => 'blog-post', 'name' => 'Blog post', 'instructions' => 'Plain words.',
            'sections' => [['name' => 'The opening', 'required' => true]],
            'data' => ['host_record', 'knowledge', 'current_figures'],
            'checks' => ['min_words' => 300, 'max_words' => 3000, 'require_cta' => false, 'require_sections' => false],
            'host_fields' => [['host' => 'blog_post', 'field' => 'title'], ['host' => 'blog_post', 'field' => 'answer']],
        ];
    }

    public function test_the_template_replaces_sections_adds_instructions_and_overrides_only_the_checks_it_sets(): void
    {
        $merged = TemplateMerge::apply($this->guide(), [
            'key' => 'story', 'name' => 'Story', 'instructions' => 'This post is a Story.',
            'sections' => [['name' => 'The moment', 'required' => true, 'look' => 'Large type.'], ['name' => ' ']],
            'checks' => ['min_words' => 600, 'max_words' => null],
            'data' => ['host_record'],
            'design' => ['layout' => 'One column.', 'signature' => 'A pull quote.', 'imagery' => ''],
            'asks' => [['question' => 'Whose story?', 'required' => true, 'options' => []], ['question' => '']],
            'reminder' => ' Get their approval. ',
            'field_rules' => ['title' => 'A plain title.', 'answer' => ' '],
        ]);

        $this->assertSame('Plain words. This post is a Story.', $merged['instructions']);
        $this->assertSame(['The moment'], array_column($merged['sections'], 'name'));
        $this->assertSame(['min_words' => 600, 'max_words' => 3000, 'require_cta' => false, 'require_sections' => false], $merged['checks']);
        $this->assertSame(['host_record'], $merged['data']);
        $this->assertSame(['layout' => 'One column.', 'signature' => 'A pull quote.'], $merged['template']['design']);
        $this->assertCount(1, $merged['template']['asks']);
        $this->assertSame('Get their approval.', $merged['template']['reminder']);
        $this->assertSame(['title' => 'A plain title.'], $merged['template']['field_rules']);
        $this->assertSame($this->guide()['host_fields'], $merged['host_fields'], 'what the guide drafts is the guide\'s');
    }

    public function test_a_template_without_sections_or_data_keeps_the_guides(): void
    {
        $merged = TemplateMerge::apply($this->guide(), ['key' => 'x', 'name' => 'X', 'sections' => [], 'data' => null, 'checks' => []]);

        $this->assertSame(['The opening'], array_column($merged['sections'], 'name'));
        $this->assertSame(['host_record', 'knowledge', 'current_figures'], $merged['data']);
        $this->assertSame($this->guide()['checks'], $merged['checks']);
        $this->assertSame('Plain words.', $merged['instructions'], 'no template instructions adds nothing');
    }
}
