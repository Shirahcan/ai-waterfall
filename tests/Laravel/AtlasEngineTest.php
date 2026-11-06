<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\Laravel\Atlas\AtlasDimension;
use Shirahcan\AiClient\Laravel\Atlas\AtlasEntity;
use Shirahcan\AiClient\Laravel\Atlas\AtlasMetric;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;
use Shirahcan\AiClient\Laravel\Atlas\DataTools;
use Shirahcan\AiClient\Laravel\Atlas\DataToolWords;
use Shirahcan\AiClient\Laravel\Atlas\EntityProvider;
use Shirahcan\AiClient\Laravel\Atlas\FakeAtlasAccess;
use Shirahcan\AiClient\Laravel\Atlas\Query\MetricContext;
use Shirahcan\AiClient\Laravel\Atlas\Query\PlanValidator;
use Shirahcan\AiClient\Laravel\Atlas\Query\QueryAudit;
use Shirahcan\AiClient\Laravel\Atlas\Query\QueryExecutor;
use Shirahcan\AiClient\Laravel\Atlas\Query\QueryTools;
use Shirahcan\AiClient\Laravel\Atlas\Query\SafeQueryBuilder;
use Shirahcan\AiClient\Time\ViewerTime;

class AtlasWidget extends Model
{
    protected $table = 'atlas_widgets';

    protected $guarded = [];
}

class AtlasPerson extends User
{
    protected $guarded = [];
}

final class WidgetProvider implements EntityProvider
{
    public function entity(): AtlasEntity
    {
        return new AtlasEntity(
            key: 'widget',
            label: 'Widget',
            plural: 'widgets',
            modelClass: AtlasWidget::class,
            audiences: ['owner', 'staff'],
            permission: 'widgets.view',
            dimensions: [
                new AtlasDimension(key: 'id', label: 'ID', path: 'id', type: 'string', selectable: false),
                new AtlasDimension(key: 'name', label: 'Name', path: 'name', type: 'string'),
                new AtlasDimension(key: 'status', label: 'Status', path: 'status', type: 'enum', groupable: true, values: ['open', 'shut']),
                new AtlasDimension(key: 'price', label: 'Price', path: 'price', type: 'money', permission: 'widgets.money'),
            ],
            metrics: [
                new AtlasMetric(key: 'widget_count', label: 'Widgets', kind: 'count', aggregate: true,
                    resolver: fn (Builder $q, MetricContext $ctx) => $q->selectRaw('COUNT(*) as widget_count')),
            ],
            relations: [],
            // The owner audience sees its own rows and nothing else; that row scope IS its access rule.
            rowScopes: ['owner' => fn (Builder $q, Authenticatable $u) => $q->where('atlas_widgets.owner_id', $u->getAuthIdentifier())],
            deepLinks: ['owner' => '/widgets/{id}'],
            defaultSort: ['key' => 'name', 'dir' => 'asc'],
            sampleQuestions: ['How many widgets are open?'],
        );
    }
}

final class RecordingAudit implements QueryAudit
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function record(Authenticatable $user, string $audience, array $targets, string $status, int $rows, int $durationMs, ?string $reason): void
    {
        $this->records[] = compact('audience', 'targets', 'status', 'rows', 'reason');
    }
}

/**
 * The data engine, in the kit (plan mploynow-mira-parity P0a-P0d): who may see what, and what a query
 * returns, with no product code at all.
 */
class AtlasEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('atlas_widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('status');
            $t->integer('price');
            $t->unsignedBigInteger('owner_id');
            $t->timestamps();
        });
        DB::table('atlas_widgets')->insert([
            ['name' => 'Anvil', 'status' => 'open', 'price' => 30, 'owner_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bolt', 'status' => 'shut', 'price' => 5, 'owner_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Crank', 'status' => 'open', 'price' => 12, 'owner_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function person(int $id): AtlasPerson
    {
        return (new AtlasPerson())->forceFill(['id' => $id]);
    }

    /** @param  list<string>  $granted */
    private function registry(?array $granted = null): AtlasRegistry
    {
        return new AtlasRegistry([new WidgetProvider()], $granted === null ? null : new FakeAtlasAccess($granted));
    }

    private function tools(AtlasRegistry $registry, ?QueryAudit $audit = null): QueryTools
    {
        $validator = new PlanValidator($registry);

        return new QueryTools($registry, $validator, new QueryExecutor($registry, new SafeQueryBuilder($registry)), $audit);
    }

    public function test_a_row_scoped_audience_sees_the_entity_without_the_broad_permission(): void
    {
        $this->assertSame(['widget'], array_map(fn ($e) => $e->key, $this->registry()->forAudience('owner', $this->person(1))));
    }

    public function test_the_kit_default_grants_no_permission(): void
    {
        // No access rule given: a permissioned entity stays hidden from an audience without a row scope.
        $this->assertSame([], $this->registry()->forAudience('staff', $this->person(9)));
        $this->assertSame(['widget'], array_map(fn ($e) => $e->key, $this->registry(['widgets.view'])->forAudience('staff', $this->person(9))));
    }

    public function test_a_field_permission_hides_the_field_even_from_the_owner(): void
    {
        $fields = fn (AtlasRegistry $r) => array_column($r->describeForAudience('owner', $this->person(1))[0]['dimensions'], 'key');

        $this->assertNotContains('price', $fields($this->registry()));
        $this->assertContains('price', $fields($this->registry(['widgets.money'])));
    }

    public function test_the_owner_lists_only_their_own_rows(): void
    {
        $run = $this->tools($this->registry())->runQuery(
            ['targets' => [['id' => 't', 'entity' => 'widget', 'select' => ['name'], 'limit' => 10]]], 'owner', $this->person(1));

        $this->assertSame(['Anvil', 'Bolt'], array_column($run['result']->targets[0]->rows, 'name'));
    }

    public function test_a_count_grouped_by_status_counts_every_permitted_row(): void
    {
        $run = $this->tools($this->registry(['widgets.view']))->runQuery(
            ['targets' => [['id' => 't', 'entity' => 'widget', 'metrics' => ['widget_count'], 'group_by' => ['status']]]], 'staff', $this->person(9));

        $byStatus = array_column($run['result']->targets[0]->rows, 'widget_count', 'status');
        $this->assertEquals(['open' => 2, 'shut' => 1], $byStatus);
    }

    public function test_an_unpermitted_column_is_dropped_never_read(): void
    {
        $run = $this->tools($this->registry())->runQuery(
            ['targets' => [['id' => 't', 'entity' => 'widget', 'select' => ['name', 'price']]]], 'owner', $this->person(1));

        $this->assertSame(['name'], array_column($run['result']->targets[0]->columns, 'key'));
        $this->assertArrayNotHasKey('price', $run['result']->targets[0]->rows[0]);
    }

    public function test_a_query_is_audited_by_key_never_by_value(): void
    {
        $audit = new RecordingAudit();
        $tools = $this->tools($this->registry(), $audit);

        $tools->runQuery(['targets' => [[
            'id' => 't', 'entity' => 'widget', 'select' => ['name'], 'filters' => [['field' => 'name', 'op' => '=', 'value' => 'Anvil']],
        ]]], 'owner', $this->person(1));
        // Staff without the broad permission: the whole plan is refused.
        $tools->runQuery(['targets' => [['id' => 't', 'entity' => 'widget', 'select' => ['name']]]], 'staff', $this->person(9));

        $this->assertSame(['ok', 'rejected'], array_column($audit->records, 'status'));
        $this->assertSame(['name ='], $audit->records[0]['targets'][0]['filters']);
        $this->assertSame(1, $audit->records[0]['rows']);
        $this->assertStringNotContainsString('Anvil', json_encode($audit->records));
    }

    public function test_data_tools_speak_the_products_words_and_draw_a_table(): void
    {
        $registry = $this->registry();
        $words = new DataToolWords(records: 'roles, applications, offers', searchTerms: '"shortlisted"', naming: 'never a bare role id');
        $data = new DataTools($this->tools($registry), $registry, $words);

        $defs = array_column($data->definitions('owner'), 'description', 'name');
        $this->assertStringContainsString('THEIR OWN records (roles, applications, offers...)', $defs[DataTools::SEARCH]);
        $this->assertStringContainsString('Record types you can query: widget (widgets).', $defs[DataTools::SEARCH]);
        $this->assertStringContainsString('(never a bare role id)', $defs[DataTools::QUERY]);

        $out = $data->run(DataTools::QUERY, ['plan' => ['targets' => [['id' => 't', 'entity' => 'widget', 'select' => ['name']]]]], $this->person(2), 'owner');
        $this->assertTrue($out['ok']);
        $this->assertSame('data_table', $out['card']['type']);
        $this->assertSame([['name' => 'Crank']], array_map(fn ($r) => ['name' => $r['name']], $out['card']['payload']['meta']['result']['targets'][0]['rows']));
    }

    public function test_viewer_time_names_the_zone_and_refuses_an_unknown_one(): void
    {
        $this->assertSame('Fri, Nov 6, 2026 at 5:00 PM WAT', ViewerTime::within('Africa/Lagos', fn () => ViewerTime::human('2026-11-06 16:00:00')));
        $this->assertSame('Fri, Nov 6, 2026 at 4:00 PM UTC', ViewerTime::within('Mars/Olympus', fn () => ViewerTime::human('2026-11-06 16:00:00')));
        $this->assertSame('Fri, Nov 6, 2026', ViewerTime::human('2026-11-06'));
    }
}
