<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirahcan\AiClient\Laravel\Tools\Standard\FindRecordTool;
use Shirahcan\AiClient\Laravel\Tools\Standard\RecordFinder;
use Shirahcan\AiClient\Laravel\Tools\Standard\StandardToolbox;
use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/** The shared `find_record`: one named record, looked up in the product's own scope (1.36). */
class FindRecordToolTest extends TestCase
{
    private function finder(array $result, bool $throws = false): RecordFinder
    {
        return new class($result, $throws) implements RecordFinder
        {
            public ?string $asked = null;

            public function __construct(private array $result, private bool $throws) {}

            public function offersLookupTo(string $audience): bool
            {
                return $audience !== 'client';
            }

            public function find(string $query, ToolActor $actor): array
            {
                $this->asked = $query;
                if ($this->throws) {
                    throw new RuntimeException('db down');
                }

                return $this->result;
            }
        };
    }

    public function test_it_is_offered_only_where_the_product_says_and_never_to_a_visitor(): void
    {
        $box = new StandardToolbox(new FindRecordTool($this->finder([])));

        $this->assertSame(['find_record'], array_column($box->definitions('admin'), 'name'));
        $this->assertSame([], $box->definitions('client'));
        $this->assertSame([], $box->definitions('public'));
    }

    public function test_every_record_gets_a_ready_made_link_at_any_depth(): void
    {
        $out = (new FindRecordTool($this->finder([
            'matches' => [['kind' => 'People', 'items' => [['title' => 'Maria Garcia', 'url' => '/admin/people/1']]]],
            'profiles' => [['name' => 'Maria Garcia', 'page' => '/admin/people/1',
                'cases' => [['title' => 'Study [Visa]', 'url' => '/admin/cases/9'], ['title' => 'No link']]]],
        ])))->run(['query' => 'Maria Garcia'], new ToolActor('u-1', 'admin'));

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['result']['found']);
        $this->assertSame('[Maria Garcia](/admin/people/1)', $out['result']['matches'][0]['items'][0]['link']);
        $this->assertSame('[Maria Garcia](/admin/people/1)', $out['result']['profiles'][0]['link']);
        $this->assertSame('[Study (Visa)](/admin/cases/9)', $out['result']['profiles'][0]['cases'][0]['link']);
        $this->assertArrayNotHasKey('link', $out['result']['profiles'][0]['cases'][1]);
    }

    public function test_no_match_and_a_failure_never_claim_the_person_does_not_exist(): void
    {
        $none = (new FindRecordTool($this->finder(['matches' => []])))->run(['query' => 'Nobody'], new ToolActor('u-1', 'admin'));
        $this->assertFalse($none['result']['found']);
        $this->assertStringContainsString('never claim the person does not exist', $none['result']['say']);

        $failed = (new FindRecordTool($this->finder([], true)))->run(['query' => 'Maria'], new ToolActor('u-1', 'admin'));
        $this->assertFalse($failed['ok']);
        $this->assertStringContainsString('never say the record does not exist', $failed['error']);
    }

    public function test_a_guest_and_an_empty_query_are_refused(): void
    {
        $tool = new FindRecordTool($this->finder([]));

        $this->assertFalse($tool->run(['query' => 'Maria'], ToolActor::guest('g'))['ok']);
        $this->assertFalse($tool->run(['query' => '  '], new ToolActor('u-1', 'admin'))['ok']);
    }
}
