<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Knowledge\KnowledgeCorpus;

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
