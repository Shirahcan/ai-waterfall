<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;

/** `ai:doctor`: a product that is not wired to ai-service says so and exits non-zero (1.35). */
class DoctorCommandTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ai-doctor-'.uniqid();
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/a.md', "---\nkey: demo.a\ntask: demo.a\nmode: text\n---\n\n## System\nYou help.\n\n## Prompt\n{{payload}}\n");
        config(['ai-client.trust_key' => 'k', 'ai-client.tools.product' => '']);
    }

    public function test_a_template_not_live_fails_and_names_it(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient());

        $this->artisan('ai:doctor', ['--path' => $this->dir])
            ->expectsOutputToContain('demo.a')
            ->assertFailed();
    }

    public function test_all_wired_passes(): void
    {
        $ai = new FakeAiClient();
        $ai->syncTasks([['key' => 'demo.a']]);
        $this->app->instance(AiClient::class, $ai);

        $this->artisan('ai:doctor', ['--path' => $this->dir])
            ->expectsOutputToContain('all checks passed')
            ->assertSuccessful();
    }

    public function test_an_empty_key_and_a_tool_host_without_a_secret_fail(): void
    {
        $ai = new FakeAiClient();
        $ai->syncTasks([['key' => 'demo.a']]);
        $this->app->instance(AiClient::class, $ai);
        config(['ai-client.trust_key' => '', 'ai-client.tools.product' => 'demo', 'ai-client.tools.secret' => '']);

        $this->artisan('ai:doctor', ['--path' => $this->dir])
            ->expectsOutputToContain('AI_SERVICE_TRUST_KEY is empty')
            ->assertFailed();
    }
}
