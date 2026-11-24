<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\Laravel\Atlas\AtlasDimension;
use Shirahcan\AiClient\Laravel\Atlas\AtlasEntity;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;
use Shirahcan\AiClient\Laravel\Atlas\DataTools;
use Shirahcan\AiClient\Laravel\Atlas\DataToolWords;
use Shirahcan\AiClient\Laravel\Atlas\EntityProvider;
use Shirahcan\AiClient\Laravel\Atlas\FakeAtlasAccess;
use Shirahcan\AiClient\Laravel\Atlas\Query\PlanValidator;
use Shirahcan\AiClient\Laravel\Atlas\Query\QueryExecutor;
use Shirahcan\AiClient\Laravel\Atlas\Query\QueryTools;
use Shirahcan\AiClient\Laravel\Atlas\Query\RecordSearch;
use Shirahcan\AiClient\Laravel\Atlas\Query\SafeQueryBuilder;

class SearchPost extends Model
{
    protected $table = 'search_posts';

    protected $guarded = [];
}

class SearchNote extends Model
{
    protected $table = 'search_notes';

    protected $guarded = [];
}

class SearchPerson extends User
{
    protected $guarded = [];
}

final class SearchPostProvider implements EntityProvider
{
    public function entity(): AtlasEntity
    {
        return new AtlasEntity(
            key: 'post', label: 'Post', plural: 'posts', modelClass: SearchPost::class,
            audiences: ['admin'], permission: 'posts.view',
            dimensions: [
                new AtlasDimension(key: 'title', label: 'Title', path: 'title', type: 'string'),
                new AtlasDimension(key: 'status', label: 'Status', path: 'status', type: 'enum', values: ['draft', 'live']),
            ],
            metrics: [], relations: [], rowScopes: [], deepLinks: [], defaultSort: null, sampleQuestions: [],
            identityKeys: ['title'],
        );
    }
}

final class SearchNoteProvider implements EntityProvider
{
    public function entity(): AtlasEntity
    {
        return new AtlasEntity(
            key: 'note', label: 'Note', plural: 'notes', modelClass: SearchNote::class,
            audiences: ['admin'], permission: 'notes.view',
            dimensions: [
                // No identity keys: the primary label names the row.
                new AtlasDimension(key: 'heading', label: 'Heading', path: 'heading', type: 'string'),
            ],
            metrics: [], relations: [], rowScopes: [], deepLinks: [], defaultSort: null, sampleQuestions: [],
        );
    }
}

/** `search_records`: which record type a name belongs to (plan porter-multi-pass-answering P1a). */
class RecordSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('search_posts', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('search_notes', function (Blueprint $t) {
            $t->id();
            $t->string('heading');
            $t->timestamps();
        });
        DB::table('search_posts')->insert([
            ['title' => 'Canada Work Permit After LMIA', 'status' => 'live'],
            ['title' => 'Study Permit Basics', 'status' => 'draft'],
        ]);
        DB::table('search_notes')->insert([['heading' => 'Call notes: work permit renewal']]);
    }

    private function person(): Authenticatable
    {
        return (new SearchPerson())->forceFill(['id' => 1]);
    }

    /** @param  list<string>  $granted */
    private function search(array $granted): RecordSearch
    {
        $registry = new AtlasRegistry([new SearchPostProvider(), new SearchNoteProvider()], new FakeAtlasAccess($granted));
        $tools = new QueryTools($registry, new PlanValidator($registry), new QueryExecutor($registry, new SafeQueryBuilder($registry)));

        return new RecordSearch($registry, $tools);
    }

    public function test_a_title_is_found_by_its_words_in_any_order_without_naming_its_type(): void
    {
        $out = $this->search(['posts.view', 'notes.view'])->search('my post Work Permit After LMIA', 'admin', $this->person());

        $this->assertSame(['post'], array_column($out['matches'], 'entity'));
        $this->assertSame('Canada Work Permit After LMIA', $out['matches'][0]['rows'][0]['title']);
        $this->assertSame(['posts', 'notes'], $out['looked_in']);
        $this->assertArrayNotHasKey('none_found', $out);
    }

    public function test_a_type_without_identity_keys_is_searched_by_its_primary_label(): void
    {
        $out = $this->search(['posts.view', 'notes.view'])->search('work permit', 'admin', $this->person());

        $this->assertEqualsCanonicalizing(['post', 'note'], array_column($out['matches'], 'entity'));
    }

    public function test_a_type_the_person_cannot_see_is_never_searched(): void
    {
        $out = $this->search(['notes.view'])->search('work permit', 'admin', $this->person());

        $this->assertSame(['note'], array_column($out['matches'], 'entity'));
        $this->assertSame(['notes'], $out['looked_in']);
    }

    public function test_no_match_says_to_try_fewer_words_never_that_it_does_not_exist(): void
    {
        $out = $this->search(['posts.view', 'notes.view'])->search('Work Permit After Positive LMIA', 'admin', $this->person());

        $this->assertSame([], $out['matches']);
        $this->assertStringContainsString('fewer or different words', $out['none_found']);
    }

    public function test_an_empty_name_lookup_says_where_it_looked_and_points_to_search_records(): void
    {
        $registry = new AtlasRegistry([new SearchPostProvider(), new SearchNoteProvider()], new FakeAtlasAccess(['posts.view', 'notes.view']));
        $tools = new QueryTools($registry, new PlanValidator($registry), new QueryExecutor($registry, new SafeQueryBuilder($registry)));

        $named = $tools->runQuery(['targets' => [['id' => 't', 'entity' => 'note', 'select' => ['heading'],
            'filters' => [['field' => 'heading', 'op' => 'contains', 'value' => 'Canada Work Permit']]]]], 'admin', $this->person());
        $this->assertStringContainsString('You looked only at notes', $named['preview']['targets'][0]['empty_means']);
        $this->assertStringContainsString('search_records', $named['preview']['targets'][0]['empty_means']);

        // Nothing named: an empty answer is still a plain answer.
        $plain = $tools->runQuery(['targets' => [['id' => 't', 'entity' => 'post', 'select' => ['title'],
            'filters' => [['field' => 'status', 'op' => '=', 'value' => 'archived']]]]], 'admin', $this->person());
        $this->assertStringContainsString('No posts match', $plain['preview']['targets'][0]['empty_means'] ?? '');
        $this->assertStringNotContainsString('search_records', $plain['preview']['targets'][0]['empty_means'] ?? '');
    }

    public function test_data_tools_offer_and_run_search_records(): void
    {
        $registry = new AtlasRegistry([new SearchPostProvider(), new SearchNoteProvider()], new FakeAtlasAccess(['posts.view']));
        $tools = new QueryTools($registry, new PlanValidator($registry), new QueryExecutor($registry, new SafeQueryBuilder($registry)));
        $data = new DataTools($tools, $registry, new DataToolWords());

        $this->assertTrue($data->handles(DataTools::RECORDS));
        $this->assertContains(DataTools::RECORDS, array_column($data->definitions('admin'), 'name'));

        $out = $data->run(DataTools::RECORDS, ['words' => 'study permit'], $this->person(), 'admin');
        $this->assertTrue($out['ok']);
        $this->assertSame('Study Permit Basics', $out['result']['matches'][0]['rows'][0]['title']);
    }
}
