<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Terminal\GuideResolver;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSessions;

/**
 * Which guide a console subject follows, in the kit (1.69.0, shared console C2b).
 *
 * ⚠ WHAT THIS GUARDS: the person's choice wins, `none` means none; a subject's host record picks its default
 * guide; otherwise the assistant picks when any guide is active; a template applies only inside its own guide and
 * `none` turns templates off for that guide; the assistant's pick is remembered but never over the person's; and
 * with the product's switch off nothing applies.
 */
class GuideResolverTest extends TestCase
{
    private FakeAiClient $ai;

    public static bool $guides = true;

    public static bool $templates = true;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('ai-client.assistant.key', 'porter');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$guides = true;
        self::$templates = true;
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $guides = $this->ai->specializations();
        $guides->publishVocabulary(['surfaces' => [['key' => 'page']], 'hosts' => [['key' => 'blog_post']], 'data' => []]);
        $guides->seed('blog-post', ['name' => 'Blog post', 'surfaces' => ['page'], 'defaults_for' => ['blog_post'], 'instructions' => 'Plain words.']);
        $guides->seed('landing', ['name' => 'Landing page', 'surfaces' => ['page'], 'instructions' => 'One action.']);
        $guides->seedTemplate('blog-post', 'story', ['name' => 'Story', 'instructions' => 'A story.', 'use_when' => 'A client story.']);
    }

    private function resolver(): GuideResolver
    {
        return new class($this->ai, $this->app->make(TerminalSessions::class)) extends GuideResolver
        {
            protected function guidesOn(): bool
            {
                return GuideResolverTest::$guides;
            }

            protected function templatesOn(): bool
            {
                return GuideResolverTest::$templates;
            }

            protected function surfaceOf(string $subjectRef): string
            {
                return 'page';
            }

            protected function hostKindOf(string $subjectRef): ?string
            {
                return $subjectRef === 'site_page:post' ? 'blog_post' : null;
            }
        };
    }

    public function test_the_rungs_person_then_host_then_the_assistant_picks(): void
    {
        $r = $this->resolver();

        $this->assertSame(['landing', 'person'], array_values(array_intersect_key($r->forSubject('site_page:post', ['key' => 'landing'], true, false), ['key' => 1, 'source' => 1])));
        $this->assertNull($r->forSubject('site_page:post', ['key' => 'none'], true));
        $this->assertSame(['blog-post', 'page'], array_values(array_intersect_key($r->forSubject('site_page:post', null, true, false), ['key' => 1, 'source' => 1])));
        $picks = $r->forSubject('site_page:plain', null, true, false);
        $this->assertSame([null, 'porter'], [$picks['key'], $picks['source']]);
    }

    public function test_a_template_applies_only_inside_its_own_guide_and_none_turns_templates_off(): void
    {
        $r = $this->resolver();
        $sessions = $this->app->make(TerminalSessions::class);

        $sessions->setTemplate('site_page:post', 'blog-post', 'story', 'Bukky', 'person');
        $applied = $r->forSubject('site_page:post', null, true);
        $this->assertSame('story', $applied['template']['key']);
        $this->assertSame('Plain words. A story.', $applied['guide']['instructions'], 'the effective guide is the merge');
        $this->assertSame('Plain words.', $applied['base']['instructions']);

        // A template of another guide applies nothing.
        $other = $r->forSubject('site_page:post', ['key' => 'landing'], true);
        $this->assertNull($other['template']);

        $this->assertTrue($r->withTemplate(['key' => 'blog-post', 'guide' => ['key' => 'blog-post']], ['guide' => 'blog-post', 'key' => 'none'])['template_off']);
    }

    public function test_the_assistants_pick_is_remembered_but_never_over_the_persons(): void
    {
        $r = $this->resolver();
        $sessions = $this->app->make(TerminalSessions::class);

        $r->rememberTemplate('site_page:a', 'blog-post', 'story');
        $this->assertSame(['story', 'porter'], [$sessions->templateChoice('site_page:a')['key'], $sessions->templateChoice('site_page:a')['source']]);

        $sessions->setTemplate('site_page:b', 'blog-post', 'none', 'Bukky', 'person');
        $r->rememberTemplate('site_page:b', 'blog-post', 'story');
        $this->assertSame('none', $sessions->templateChoice('site_page:b')['key']);
    }

    public function test_with_the_products_switches_off_nothing_applies(): void
    {
        self::$guides = false;
        $this->assertNull($this->resolver()->forSubject('site_page:post', null, true));
        $this->assertSame([], $this->resolver()->catalogue('page'));

        self::$guides = true;
        self::$templates = false;
        $this->assertNull($this->resolver()->templateChoice('site_page:post'));
        $this->assertSame(['story'], array_column(GuideResolver::templateOptions(['templates' => [['key' => 'story', 'name' => 'Story']]]), 'key'));
    }
}
