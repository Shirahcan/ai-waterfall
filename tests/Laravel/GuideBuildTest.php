<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use RuntimeException;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Terminal\GuideBuild;
use Shirahcan\AiClient\Laravel\Terminal\GuideResolver;
use Shirahcan\AiClient\Laravel\Terminal\HostFieldDrafter;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSessions;

/**
 * A guided Build in the kit (1.80.0, shared console C5; Portify's BuildSpecialization and HostFieldDraft until then).
 *
 * ⚠ WHAT THIS GUARDS: a document the guides are not for gets none; the host record's kind picks its default guide
 * and names it in the step line; the assistant's pick is kept only from its menu; a template pick is laid over the
 * guide; the person's own structure turns every check off; the product's shape misses join the guide's; and the
 * drafter never redrafts a filled field, keeps each value to its column, and a failed call offers nothing.
 */
class GuideBuildTest extends TestCase
{
    private FakeAiClient $ai;

    /** @var array<string, mixed>|null */
    public static ?array $answer = ['summary' => 'A short answer.', 'title' => 'Ignored', 'extra' => 'dropped'];

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
        self::$answer = ['summary' => 'A short answer.', 'title' => 'Ignored', 'extra' => 'dropped'];
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $guides = $this->ai->specializations();
        $guides->publishVocabulary(['surfaces' => [['key' => 'page']], 'hosts' => [['key' => 'blog_post']], 'data' => []]);
        $guides->seed('blog-post', ['name' => 'Blog post', 'surfaces' => ['page'], 'defaults_for' => ['blog_post'], 'instructions' => 'Plain words.', 'host_fields' => [['host' => 'blog_post', 'field' => 'summary'], ['host' => 'blog_post', 'field' => 'title']]]);
        $guides->seed('landing', ['name' => 'Landing page', 'surfaces' => ['page'], 'instructions' => 'One action.']);
        $guides->seedTemplate('blog-post', 'story', ['name' => 'Story', 'instructions' => 'A story.', 'use_when' => 'A client story.']);
    }

    private function resolver(): GuideResolver
    {
        return new class($this->ai, $this->app->make(TerminalSessions::class)) extends GuideResolver
        {
            protected function guidesOn(): bool
            {
                return true;
            }

            protected function templatesOn(): bool
            {
                return true;
            }

            protected function surfaceOf(string $subjectRef): string
            {
                return 'page';
            }

            protected function hostKindOf(string $subjectRef): ?string
            {
                return null;
            }
        };
    }

    private function drafter(): HostFieldDrafter
    {
        return new class extends HostFieldDrafter
        {
            protected function fieldSpecs(string $hostKind): array
            {
                return $hostKind === 'blog_post' ? ['summary' => ['label' => 'Summary', 'max' => 10], 'title' => ['label' => 'Title', 'max' => 50]] : [];
            }

            protected function onlyWhileEmpty(): array
            {
                return ['blog_post' => ['title']];
            }

            protected function text(array $nodes): string
            {
                return implode(' ', array_map(static fn (array $n) => (string) ($n['text'] ?? ''), $nodes));
            }

            protected function recordFacts(array $host): array
            {
                return ['title' => $host['record']['title'] ?? ''];
            }

            protected function call(array $payload): ?array
            {
                if (GuideBuildTest::$answer === null) {
                    throw new RuntimeException('The model did not answer.');
                }

                return GuideBuildTest::$answer;
            }
        };
    }

    /** @param array{kind: string, label: string, record: mixed}|null $host */
    private function build(?array $host, bool $skips = false): GuideBuild
    {
        return new class($this->resolver(), $this->drafter(), $host, $skips) extends GuideBuild
        {
            public function __construct(private GuideResolver $r, private HostFieldDrafter $d, private ?array $h, private bool $skip) {}

            protected function resolver(): GuideResolver
            {
                return $this->r;
            }

            protected function surfaceFor(string $mode): string
            {
                return 'page';
            }

            protected function skips(mixed $page, string $mode): bool
            {
                return $this->skip || $mode === 'element';
            }

            protected function host(mixed $page): ?array
            {
                return $this->h;
            }

            protected function templatesOn(): bool
            {
                return true;
            }

            protected function gather(array $guide, ?array $host, string $topic): array
            {
                return ['topic' => $topic];
            }

            protected function nodesOf(?string $source): array
            {
                return $source === null || $source === '' ? [] : [['primitive' => 'heading', 'level' => 1, 'text' => $source]];
            }

            protected function misses(array $guide, array $nodes): array
            {
                return ['It has a call to action'];
            }

            protected function shapeMisses(array $state, array $nodes): array
            {
                return ($state['host_kind'] ?? null) === 'blog_post' ? [GuideBuild::MAIN_HEADING_MISS.', "'.$nodes[0]['text'].'"'] : [];
            }

            protected function drafter(): ?HostFieldDrafter
            {
                return $this->d;
            }
        };
    }

    private function blogPost(string $title = ''): array
    {
        return ['kind' => 'blog_post', 'label' => 'the blog post “Study permits”', 'record' => ['id' => 'p1', 'title' => $title]];
    }

    public function test_a_document_the_guides_are_not_for_gets_none(): void
    {
        $state = $this->build($this->blogPost(), skips: true)->start('page', null, []);

        $this->assertNull($state['guide']);
        $this->assertSame('blog_post', $state['host_kind']);
        $this->assertNull($this->build(null)->start('element', null, [])['guide']);
    }

    public function test_the_host_picks_its_default_guide_and_the_step_line_names_it(): void
    {
        $build = $this->build($this->blogPost());
        $state = $build->start('page', null, []);

        $this->assertSame('Blog post', $state['applied']['name']);
        $this->assertSame(GuideResolver::SOURCE_PAGE, $state['applied']['source']);
        $this->assertSame(['Following the Blog post guide', 'This page is the blog post “Study permits”.'], $build->stepLine($state, null));
        $this->assertSame(['story'], array_column($state['template_menu'], 'key'));
        $this->assertSame(['key' => 'blog-post', 'name' => 'Blog post', 'source' => 'page'], GuideBuild::record($state));
    }

    public function test_the_assistant_picks_only_from_its_menu_and_a_template_is_laid_over_the_guide(): void
    {
        $build = $this->build(null);
        $state = $build->start('page', null, []);
        $this->assertNull($state['guide']);
        $this->assertSame(['blog-post', 'landing'], array_column(GuideBuild::needsFacts($state)['specializations'], 'key'));

        $this->assertNull($build->adopt($state, 'nonsense')['guide']);
        $state = $build->adopt($state, 'blog-post');
        $this->assertSame('Blog post', $state['applied']['name']);
        $this->assertSame(['Following the Blog post guide', 'I chose it for this ask.'], $build->stepLine($state, null));

        $state = $build->adoptTemplate($state, 'story');
        $this->assertSame('Story', $state['template']['name']);
        $this->assertSame('Following the Blog post guide: Story', $build->stepLine($state, null)[0]);
        $this->assertSame([], $state['template_menu']);
    }

    public function test_the_persons_structure_turns_every_check_off_and_the_shape_misses_join_the_guides(): void
    {
        $build = $this->build($this->blogPost());
        $state = $build->start('page', null, []);

        $misses = $build->check($state, 'Study permits');
        $this->assertSame('It has a call to action', $misses[0]);
        $this->assertTrue(GuideBuild::isShapeMiss($misses[1]));
        $this->assertSame([], $build->check($state, ''), 'a document that cannot be read is not judged');

        $own = GuideBuild::theirStructure($state, true);
        $this->assertSame([], $build->check($own, 'Study permits'));
        $this->assertStringContainsString('You set the structure', $build->stepLine($own, null)[1]);
        $this->assertTrue($build->forWriter($own, null, 'permits')['person_set_structure']);
        $this->assertSame(['topic' => 'permits'], $build->forWriter($own, null, 'permits')['fetched']);
    }

    public function test_the_drafter_keeps_each_field_to_its_column_and_never_redrafts_a_filled_one(): void
    {
        $state = $this->build($this->blogPost('My own title'))->start('page', null, []);
        $offer = $this->build($this->blogPost('My own title'))->propose($state, null, 'Study permits');

        $this->assertSame(['summary' => 'A short an'], $offer['values'], 'the title is the person\'s, and the summary keeps to its 10 characters');
        $this->assertSame('p1', $offer['record_id']);
        $this->assertSame('Suggested for the blog post “Study permits”', $offer['title']);

        self::$answer = null;
        $this->assertNull($this->build($this->blogPost())->propose($state, null, 'Study permits'), 'a failed call offers nothing');
    }

    public function test_reader_questions_are_capped_and_shaped(): void
    {
        $questions = array_map(static fn (int $i) => ['q' => "Q{$i}?", 'a' => str_repeat('a', 30)], range(1, 12));
        $values = HostFieldDrafter::clean(['reader_questions' => [...$questions, 'junk', ['q' => '']]], ['reader_questions' => ['label' => 'Questions', 'max' => 20]]);

        $this->assertCount(8, $values['reader_questions']);
        $this->assertSame(20, mb_strlen($values['reader_questions'][0]['a']));
    }
}
