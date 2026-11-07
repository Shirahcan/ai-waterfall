<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Knowledge\ChecksKnowledgeCorpus;
use Shirahcan\AiClient\Laravel\Knowledge\KnowledgeCorpus;
use Shirahcan\AiClient\Laravel\Knowledge\KnowledgeGates;
use Shirahcan\AiClient\Laravel\Knowledge\KnowledgeSource;
use Shirahcan\AiClient\Laravel\Knowledge\KnowledgeSyncCommand;

/** A knowledge folder read the same way in every product, and published by `ai:knowledge-sync`. */
class KnowledgeCorpusTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ai-knowledge-'.uniqid();
        mkdir($this->dir.'/01-nav', 0777, true);
    }

    private function file(string $relative, string $front, string $body = 'An answer.'): void
    {
        file_put_contents($this->dir.'/'.$relative, "---\n{$front}\n---\n\n{$body}\n");
    }

    public function test_an_entry_for_a_dark_feature_is_held_back_and_a_typo_fails_closed(): void
    {
        $gates = new class implements KnowledgeGates
        {
            public function isOpen(string $key): ?bool
            {
                return ['on.enabled' => true, 'off.enabled' => false][$key] ?? null;
            }
        };

        $this->assertNull(KnowledgeSyncCommand::firstClosedGate(['on.enabled'], $gates));
        $this->assertSame('off.enabled', KnowledgeSyncCommand::firstClosedGate(['on.enabled', 'off.enabled'], $gates));
        $this->assertSame('typo.enabled', KnowledgeSyncCommand::firstClosedGate(['typo.enabled'], $gates));
        $this->assertSame('on.enabled', KnowledgeSyncCommand::firstClosedGate(['on.enabled'], null), 'a product with no switches bound serves no gated entry');
    }

    public function test_the_sync_holds_gated_entries_back_and_keeps_what_a_source_adds(): void
    {
        $this->file('01-nav/open.md', "slug: nav.open\nquestion: Open?\ndescription: Open.");
        $this->file('01-nav/dark.md', "slug: nav.dark\nquestion: Dark?\ndescription: Dark.\nrequires_gates: [off.enabled]");

        $this->app->bind(KnowledgeGates::class, fn () => new class implements KnowledgeGates
        {
            public function isOpen(string $key): ?bool
            {
                return false;
            }
        });
        $this->app->bind('faq-source', fn () => new class implements KnowledgeSource
        {
            public function entries(): array
            {
                return [['slug' => 'faq.1', 'status' => 'published', 'question' => 'Q?', 'answer' => 'A.', 'extra' => ['kind' => 'admin']]];
            }
        });
        $this->app->tag('faq-source', 'ai-client.knowledge.sources');

        $ai = new FakeAiClient();
        $this->app->instance(AiClient::class, $ai);

        $this->artisan('ai:knowledge-sync', ['--path' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('3 entries would be synced (1 held back by a switch)')->assertExitCode(0);
        $this->assertNull($ai->knowledge()->entry('nav.open'), 'a dry run sends nothing');

        $this->artisan('ai:knowledge-sync', ['--path' => $this->dir])->expectsOutputToContain('held=1')->assertExitCode(0);

        $this->assertSame('published', $ai->knowledge()->entry('nav.open')['status']);
        $this->assertSame('unpublished', $ai->knowledge()->entry('nav.dark')['status']);
        $this->assertSame('gate off.enabled is off', $ai->knowledge()->entry('nav.dark')['extra']['unpublished_reason']);
        $this->assertSame('admin', $ai->knowledge()->entry('faq.1')['extra']['kind'], 'what a source adds survives the retire step');
    }

    public function test_the_regulated_expiry_and_route_checks_can_fail(): void
    {
        $checks = new class
        {
            use ChecksKnowledgeCorpus;

            /** @var list<array<string, mixed>> */
            public array $docs = [];

            protected function knowledgeDocs(): array
            {
                return $this->docs;
            }

            public function routes(array $screens): void
            {
                $this->assertEveryRouteExists($screens);
            }

            public function regulated(): void
            {
                $this->assertNoRegulatedEntryExpired();
            }
        };

        $doc = ['slug' => 'a', 'path' => 'a.md', 'requires_routes' => ['/gone'], 'regulated' => true, 'expires_at' => '2020-01-01'];
        $checks->docs = [$doc];

        try {
            $checks->routes(['/here']);
            $this->fail('a route with no screen must fail');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('/gone', $e->getMessage());
        }

        try {
            $checks->regulated();
            $this->fail('an expired regulated entry must fail');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('expired on 2020-01-01', $e->getMessage());
        }

        $checks->docs = [['requires_routes' => ['/here/deep']] + $doc];
        $checks->routes(['/here/deep/[id]']);
    }

    public function test_a_regulated_entry_reaches_the_service_flagged_and_with_its_link(): void
    {
        // ai-service's search_knowledge reads extra.regulated and extra.source_url, nothing else.
        $this->file('fee.md', "slug: fee\nquestion: Fee?\ndescription: The fee.\nregulated: true\nexpires_at: 2027-01-31\n"
            ."sources: [not a link, 'https://www.canada.ca/en/fees.html']");
        $this->file('01-nav/plain.md', "slug: plain\nquestion: Where?\ndescription: Here.");

        $wire = array_map(fn ($d) => KnowledgeCorpus::toWire($d), (new KnowledgeCorpus($this->dir))->all());
        $bySlug = array_column($wire, 'extra', 'slug');

        $this->assertTrue($bySlug['fee']['regulated']);
        $this->assertSame('https://www.canada.ca/en/fees.html', $bySlug['fee']['source_url']);
        $this->assertFalse($bySlug['plain']['regulated']);
        $this->assertNull($bySlug['plain']['source_url']);
    }

    public function test_an_entry_is_read_with_its_partition_hard_filter_and_extra_keys(): void
    {
        $this->file('01-nav/a.md', "slug: nav.a\nquestion: Where?\ndescription: Here.\naudiences: [client]\nrequires_gates: [x.enabled]\nreviewed_at: 2026-10-30");
        file_put_contents($this->dir.'/README.md', 'about the corpus');

        $docs = (new KnowledgeCorpus($this->dir))->all();

        $this->assertCount(1, $docs, 'README is not an entry');
        $this->assertSame('01-nav', $docs[0]['partition']);
        $this->assertSame(['client'], $docs[0]['audiences']);
        $this->assertSame(['x.enabled'], $docs[0]['meta']['requires_gates']);
        $this->assertSame('2026-10-30', $docs[0]['reviewed_at']);
        $this->assertSame('client', KnowledgeCorpus::toWire($docs[0])['audiences'][0]);
    }

    public function test_a_regulated_entry_without_an_expiry_throws(): void
    {
        $this->file('fee.md', "slug: fee\nquestion: Fee?\ndescription: The fee.\nregulated: true");

        $this->expectExceptionMessage('regulated: true');
        (new KnowledgeCorpus($this->dir))->all();
    }

    public function test_two_files_claiming_one_slug_throw(): void
    {
        $this->file('a.md', "slug: same\nquestion: Q\ndescription: D");
        $this->file('01-nav/b.md', "slug: same\nquestion: Q\ndescription: D");

        $this->expectExceptionMessage('Duplicate knowledge slug `same`');
        (new KnowledgeCorpus($this->dir))->all();
    }

    public function test_the_command_publishes_and_never_retires_from_an_empty_read(): void
    {
        $ai = new FakeAiClient();
        $this->app->instance(AiClient::class, $ai);

        $this->artisan('ai:knowledge-sync', ['--path' => $this->dir.'/missing'])
            ->expectsOutputToContain('nothing retired')
            ->assertSuccessful();

        $this->file('a.md', "slug: one\nquestion: Q\ndescription: D");
        $this->artisan('ai:knowledge-sync', ['--path' => $this->dir])->assertSuccessful();

        $this->assertSame('one', $ai->knowledge()->entry('one')['slug'] ?? null);
    }
}
