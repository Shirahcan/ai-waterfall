<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Assistant\SpecializationCatalogue;
use Shirahcan\AiClient\Assistant\TemplateCatalogue;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Console\ConsoleRoutes;
use Shirahcan\AiClient\Laravel\Terminal\TemplatedSubject;
use Shirahcan\AiClient\Laravel\Terminal\TerminalRoutes;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSessions;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSubject;

/**
 * 1.65 (owner 2026-11-12): templates, the formats inside a guide ("Story" under "Blog post"). The sync seeds them
 * like guides (an admin's edit is kept), and the console keeps the subject's template in its own slot.
 */
class TemplatesTest extends TestCase
{
    private FakeAiClient $ai;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('ai-client.assistant.key', 'porter');
        $app['config']->set('ai-client.specializations.catalogue', TemplatesTestCatalogue::class);
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('v1/admin')->group(fn () => TerminalRoutes::mount('console'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $ai = $this->ai;
        $this->app->bind(TerminalSubject::class, fn () => new class($ai) implements TerminalSubject, TemplatedSubject
        {
            public function __construct(private FakeAiClient $ai) {}

            public function ref(Request $request, string $kind, string $id): ?string
            {
                return $kind === 'site_page' && in_array($id, ['p1', 'p2'], true) ? 'site_page:'.$id : null;
            }

            public function label(string $ref): string
            {
                return 'A post';
            }

            public function sizing(string $ref): array
            {
                return ['design_chars' => 100, 'budget_tokens' => 2000];
            }

            public function specialization(string $ref, ?array $choice): ?array
            {
                // p2 has no guide at all.
                return $ref === 'site_page:p2' ? null : ['key' => 'blog-post', 'name' => 'Blog post', 'source' => 'page'];
            }

            public function specializations(string $ref): array
            {
                return [['key' => 'blog-post', 'name' => 'Blog post', 'use_when' => 'An article.']];
            }

            public function templateGuide(string $ref): ?string
            {
                return $ref === 'site_page:p2' ? null : 'blog-post';
            }

            public function templates(string $ref): array
            {
                return $ref === 'site_page:p2' ? [] : array_map(static fn ($t) => [
                    'key' => $t['key'], 'name' => $t['name'], 'use_when' => $t['use_when'], 'group' => $t['group'],
                    'example_titles' => $t['example_titles'], 'starter' => $t['starter'],
                ], $this->ai->specializations()->templates('blog-post', true));
            }

            public function template(string $ref, ?array $choice): ?array
            {
                if ($choice === null || $choice['key'] === 'none' || $choice['guide'] !== 'blog-post') {
                    return null;
                }
                foreach ($this->templates($ref) as $t) {
                    if ($t['key'] === $choice['key']) {
                        return ['key' => $t['key'], 'name' => $t['name'], 'source' => $choice['source']];
                    }
                }

                return null;
            }
        });
    }

    public function test_the_sync_seeds_templates_in_order_and_keeps_an_admins_edit(): void
    {
        $this->artisan('ai:specializations-sync')
            ->expectsOutputToContain('template: blog-post/story')
            ->expectsOutputToContain('Templates synced: created=2 refreshed=0 unchanged=0 kept=0')
            ->assertSuccessful();

        $rows = $this->ai->specializations()->templates('blog-post');
        $this->assertSame(['story', 'how-to'], array_column($rows, 'key'));
        $this->assertSame('sync', $rows[0]['updated_by']);

        $this->ai->specializations()->putTemplate('blog-post', 'story', ['instructions' => 'Our own words.', 'updated_by' => 'Bukky']);
        $this->artisan('ai:specializations-sync')->expectsOutputToContain('Templates synced: created=0 refreshed=0 unchanged=1 kept=1')->assertSuccessful();
        $this->assertSame('Our own words.', $this->ai->specializations()->templates('blog-post')[0]['instructions']);

        $listed = $this->ai->specializations()->list('page', true, true)['specializations'];
        $this->assertSame(['story', 'how-to'], array_column($listed[0]['templates'], 'key'));
    }

    public function test_a_catalogue_without_templates_syncs_exactly_as_before(): void
    {
        $this->app['config']->set('ai-client.specializations.catalogue', TemplatesTestGuidesOnly::class);

        $this->artisan('ai:specializations-sync')
            ->expectsOutputToContain('created=1 refreshed=0 unchanged=0 kept=0')
            ->doesntExpectOutputToContain('Templates synced')
            ->assertSuccessful();
    }

    public function test_the_fake_refuses_what_the_service_refuses_for_a_template(): void
    {
        $this->artisan('ai:specializations-sync')->assertSuccessful();
        $guides = $this->ai->specializations();

        foreach ([
            fn () => $guides->putTemplate('no-guide', 'story', ['name' => 'Story']),
            fn () => $guides->putTemplate('blog-post', 'new-one', ['instructions' => 'x']),
            fn () => $guides->putTemplate('blog-post', 'story', ['data' => ['salaries']]),
            fn () => $guides->putTemplate('blog-post', 'story', ['field_rules' => ['slug' => 'x']]),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("refusal {$i} was not refused");
            } catch (AiRequestRefusedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($guides->deleteTemplate('blog-post', 'how-to'));
        $this->assertFalse($guides->deleteTemplate('blog-post', 'how-to'));
    }

    public function test_the_console_editor_rows_include_the_templates(): void
    {
        $paths = array_map(static fn ($row) => $row[0].' '.$row[1], ConsoleRoutes::TABLE);
        foreach (['GET specializations/{key}/templates', 'PUT specializations/{key}/templates/{template}', 'DELETE specializations/{key}/templates/{template}'] as $row) {
            $this->assertContains($row, $paths);
        }
    }

    public function test_the_template_choice_has_its_own_slot_and_only_an_offered_template_can_be_chosen(): void
    {
        $this->artisan('ai:specializations-sync')->assertSuccessful();
        $as = $this->actingAs(new GenericUser(['id' => 'u-1', 'name' => 'Bukky']));

        $as->getJson('/v1/admin/console/site_page/p1')->assertOk()
            ->assertJsonPath('data.template', null)
            ->assertJsonPath('data.template_choice', null)
            ->assertJsonCount(2, 'data.templates')
            ->assertJsonPath('data.templates.0.starter', 'Write the story of [who].');

        $as->putJson('/v1/admin/console/site_page/p1/template', ['key' => 'how-to'])->assertOk()
            ->assertJsonPath('data.choice.guide', 'blog-post')
            ->assertJsonPath('data.choice.by', 'Bukky')
            ->assertJsonPath('data.choice.source', 'person')
            ->assertJsonPath('data.template.name', 'How-to guide');
        $as->getJson('/v1/admin/console/site_page/p1')->assertOk()
            ->assertJsonPath('data.template_choice.key', 'how-to')
            ->assertJsonPath('data.specialization_choice', null);

        $as->putJson('/v1/admin/console/site_page/p1/template', ['key' => 'none'])->assertOk()->assertJsonPath('data.template', null);
        $as->putJson('/v1/admin/console/site_page/p1/template', ['key' => 'made-up'])->assertStatus(422);
        $as->putJson('/v1/admin/console/site_page/p1/template', ['key' => 'Bad Key'])->assertStatus(422);
        $as->putJson('/v1/admin/console/site_page/p1/template', ['key' => null])->assertOk()->assertJsonPath('data.choice', null);
        $as->putJson('/v1/admin/console/site_page/p2/template', ['key' => 'story'])->assertStatus(422);
        $as->putJson('/v1/admin/console/site_page/p9/template', ['key' => 'story'])->assertNotFound();
    }

    public function test_the_assistants_remembered_pick_reads_back_as_its_own(): void
    {
        $sessions = $this->app->make(TerminalSessions::class);

        $sessions->setTemplate('site_page:p1', 'blog-post', 'story', 'Porter', 'porter');
        $this->assertSame(['guide' => 'blog-post', 'key' => 'story', 'source' => 'porter'], array_intersect_key($sessions->templateChoice('site_page:p1'), array_flip(['guide', 'key', 'source'])));

        $this->assertNull($sessions->setTemplate('site_page:p1', null, 'story', 'Porter'));
        $this->assertNull($sessions->templateChoice('site_page:p1'));
    }
}

class TemplatesTestGuidesOnly implements SpecializationCatalogue
{
    public function vocabulary(): array
    {
        return [
            'surfaces' => [['key' => 'page', 'label' => 'Pages']],
            'hosts'    => [['key' => 'blog_post', 'label' => 'Blog post', 'fields' => [['key' => 'title', 'label' => 'Title', 'max' => 200]]]],
            'data'     => [['key' => 'host_record', 'label' => 'The post itself'], ['key' => 'knowledge', 'label' => 'What we do']],
        ];
    }

    public function defaults(): array
    {
        return [
            'blog-post' => ['name' => 'Blog post', 'use_when' => 'An article.', 'surfaces' => ['page'], 'defaults_for' => ['blog_post'],
                'data' => ['host_record'], 'host_fields' => [['host' => 'blog_post', 'field' => 'title']]],
        ];
    }
}

class TemplatesTestCatalogue extends TemplatesTestGuidesOnly implements TemplateCatalogue
{
    public function templates(): array
    {
        return [
            'blog-post' => [
                'story' => ['name' => 'Story', 'group' => 'Tell a story', 'use_when' => 'A journey.', 'example_titles' => ['From refused to approved'],
                    'starter' => 'Write the story of [who].', 'field_rules' => ['title' => 'A plain title.']],
                'how-to' => ['name' => 'How-to guide', 'group' => 'Guide someone through it', 'use_when' => 'The steps.', 'example_titles' => [],
                    'starter' => '', 'data' => ['knowledge']],
            ],
        ];
    }
}
