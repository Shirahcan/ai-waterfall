<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Terminal\TerminalRoutes;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSubject;

/**
 * Several console surfaces in one product (1.72.0, shared console C6a): each mount may name its own subject and a
 * route name, so a second surface in another area (Portify's email templates beside its pages) answers for its own
 * subjects, refuses the other mount's, and never collides on a route name. A mount with no subject keeps serving the
 * bound `TerminalSubject`, as before.
 */
class TerminalMountsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('ai-client.assistant.key', 'porter');
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('v1/admin/website')->group(fn () => TerminalRoutes::mount('console'));
        $router->prefix('v1/admin/email-templates')->group(fn () => TerminalRoutes::mount('console', EmailTemplateSubjectStub::class, 'email'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AiClient::class, new FakeAiClient);
        $this->app->bind(TerminalSubject::class, fn () => new class implements TerminalSubject
        {
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
        });
    }

    private function as(string $id): static
    {
        return $this->actingAs(new GenericUser(['id' => $id, 'name' => 'Bukky']));
    }

    public function test_the_default_mount_still_serves_the_bound_subject_and_nothing_else(): void
    {
        $this->as('u-1')->getJson('/v1/admin/website/console/site_page/p1')->assertOk()->assertJsonPath('data.subject.label', 'Home page');
        $this->as('u-1')->getJson('/v1/admin/website/console/email_template/e1')->assertNotFound();
    }

    public function test_a_named_mount_serves_its_own_subject_and_refuses_the_others(): void
    {
        $this->as('u-1')->getJson('/v1/admin/email-templates/console/email_template/e1')->assertOk()->assertJsonPath('data.subject.label', 'Welcome email');
        $this->as('u-1')->getJson('/v1/admin/email-templates/console/site_page/p1')->assertNotFound();
        $this->as('u-1')->postJson('/v1/admin/email-templates/console/email_template/e1/sessions')->assertOk();
    }

    public function test_two_mounts_never_share_a_route_name(): void
    {
        $this->assertTrue(Route::has('assistant.terminal.show'));
        $this->assertTrue(Route::has('assistant.terminal.email.show'));
        $this->assertStringContainsString('/v1/admin/email-templates/console/email_template/e1', route('assistant.terminal.email.show', ['kind' => 'email_template', 'id' => 'e1']));
        $this->assertStringContainsString('/v1/admin/website/console/site_page/p1', route('assistant.terminal.show', ['kind' => 'site_page', 'id' => 'p1']));
    }
}

/** The second surface's subject, made by the container from the mount's route default. */
final class EmailTemplateSubjectStub implements TerminalSubject
{
    public function ref(Request $request, string $kind, string $id): ?string
    {
        return $kind === 'email_template' && $id === 'e1' ? 'email_template:e1' : null;
    }

    public function label(string $ref): string
    {
        return 'Welcome email';
    }

    public function sizing(string $ref): array
    {
        return ['design_chars' => 50, 'budget_tokens' => 2000];
    }
}
