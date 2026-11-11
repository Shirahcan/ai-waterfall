<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Assistant\SpecializationCatalogue;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Console\ConsoleRoutes;
use Shirahcan\AiClient\Laravel\Terminal\SpecializedSubject;
use Shirahcan\AiClient\Laravel\Terminal\TerminalRoutes;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSubject;

/**
 * Owner 2026-11-11: the console follows "specializations", named guides for one kind of output. The kit
 * publishes a product's vocabulary and starting guides (never over an admin's edit), proxies the console's
 * editor, and keeps the person's choice per subject beside its brief.
 */
class SpecializationsTest extends TestCase
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
        $app['config']->set('ai-client.specializations.catalogue', TestCatalogue::class);
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
        $this->app->bind(TerminalSubject::class, fn () => new class($ai) implements TerminalSubject, SpecializedSubject
        {
            public function __construct(private FakeAiClient $ai) {}

            public function ref(Request $request, string $kind, string $id): ?string
            {
                return $kind === 'site_page' && $id === 'p1' ? 'site_page:p1' : null;
            }

            public function label(string $ref): string
            {
                return 'Home page';
            }

            public function sizing(string $ref): array
            {
                return ['design_chars' => 100, 'budget_tokens' => 2000];
            }

            public function specialization(string $ref, ?array $choice): ?array
            {
                if (($choice['key'] ?? null) === 'none') {
                    return null;
                }
                $key = $choice['key'] ?? 'blog-post';

                return ['key' => $key, 'name' => $this->ai->specializations()->get($key)['name'] ?? $key, 'source' => $choice === null ? 'page' : 'person'];
            }

            public function specializations(string $ref): array
            {
                return array_map(static fn ($g) => ['key' => $g['key'], 'name' => $g['name'], 'use_when' => $g['use_when']],
                    $this->ai->specializations()->list('page', true)['specializations']);
            }
        });
    }

    public function test_the_sync_publishes_the_vocabulary_then_seeds_and_never_overwrites_an_edit(): void
    {
        $this->artisan('ai:specializations-sync')->expectsOutputToContain('created=2 kept=0')->assertSuccessful();
        $this->assertSame(['page'], array_column($this->ai->specializations()->vocabulary()['surfaces'], 'key'));

        $this->ai->specializations()->put('blog-post', ['instructions' => 'The admin\'s own words.']);
        $this->artisan('ai:specializations-sync')->expectsOutputToContain('created=0 kept=2')->assertSuccessful();
        $this->assertSame('The admin\'s own words.', $this->ai->specializations()->get('blog-post')['instructions']);
    }

    public function test_the_fake_refuses_what_the_service_refuses(): void
    {
        $guides = $this->ai->specializations();
        try {
            $guides->put('x', ['name' => 'X', 'surfaces' => ['page']]);
            $this->fail('a guide before any vocabulary must be refused');
        } catch (AiRequestRefusedException $e) {
            $this->assertSame('no_vocabulary', $e->errorCode);
        }

        $this->artisan('ai:specializations-sync')->assertSuccessful();
        $this->expectException(AiRequestRefusedException::class);
        $guides->put('x', ['name' => 'X', 'surfaces' => ['page'], 'data' => ['salaries']]);
    }

    public function test_the_console_editor_rows_are_whitelisted(): void
    {
        $paths = array_map(static fn ($row) => $row[0].' '.$row[1], ConsoleRoutes::TABLE);
        foreach (['GET specializations', 'POST specializations/draft', 'GET specializations/{key}', 'PUT specializations/{key}', 'DELETE specializations/{key}', 'GET specializations-vocabulary'] as $row) {
            $this->assertContains($row, $paths);
        }
        // The vocabulary is the product's code: the console never writes it.
        $this->assertNotContains('PUT specializations-vocabulary', $paths);
    }

    public function test_the_persons_choice_belongs_to_the_subject_and_only_an_offered_guide_can_be_chosen(): void
    {
        $this->artisan('ai:specializations-sync')->assertSuccessful();
        $as = $this->actingAs(new GenericUser(['id' => 'u-1', 'name' => 'Bukky']));

        $as->getJson('/v1/admin/console/site_page/p1')->assertOk()
            ->assertJsonPath('data.specialization.source', 'page')
            ->assertJsonPath('data.specialization_choice', null)
            ->assertJsonCount(2, 'data.specializations');

        $as->putJson('/v1/admin/console/site_page/p1/specialization', ['key' => 'landing-page'])->assertOk()
            ->assertJsonPath('data.choice.by', 'Bukky')
            ->assertJsonPath('data.specialization.name', 'Landing page')
            ->assertJsonPath('data.specialization.source', 'person');
        $as->getJson('/v1/admin/console/site_page/p1')->assertOk()->assertJsonPath('data.specialization_choice.key', 'landing-page');

        $as->putJson('/v1/admin/console/site_page/p1/specialization', ['key' => 'none'])->assertOk()->assertJsonPath('data.specialization', null);
        $as->putJson('/v1/admin/console/site_page/p1/specialization', ['key' => 'made-up'])->assertStatus(422);
        $as->putJson('/v1/admin/console/site_page/p1/specialization', ['key' => 'Bad Key'])->assertStatus(422);
        $as->putJson('/v1/admin/console/site_page/p1/specialization', ['key' => null])->assertOk()->assertJsonPath('data.choice', null)
            ->assertJsonPath('data.specialization.source', 'page');
        $as->putJson('/v1/admin/console/site_page/p9/specialization', ['key' => 'none'])->assertNotFound();
    }
}

class TestCatalogue implements SpecializationCatalogue
{
    public function vocabulary(): array
    {
        return [
            'surfaces' => [['key' => 'page', 'label' => 'Pages']],
            'hosts'    => [['key' => 'blog_post', 'label' => 'Blog post', 'fields' => [['key' => 'answer', 'label' => 'Answer', 'max' => 600]]]],
            'data'     => [['key' => 'host_record', 'label' => 'The post itself']],
        ];
    }

    public function defaults(): array
    {
        return [
            'blog-post'    => ['name' => 'Blog post', 'use_when' => 'An article.', 'surfaces' => ['page'], 'defaults_for' => ['blog_post'], 'data' => ['host_record']],
            'landing-page' => ['name' => 'Landing page', 'use_when' => 'One offer.', 'surfaces' => ['page']],
        ];
    }
}
