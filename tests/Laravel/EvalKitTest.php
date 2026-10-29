<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Laravel\Eval\EvalDriver;
use Shirahcan\AiClient\Laravel\Eval\EvalScorer;

/** The shared eval runner (`ai:eval`): one scorer, the product's rules as data, a driver per product. */
class EvalKitTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-client.trust_key', 'trust');
    }

    public function test_the_scorer_checks_mentions_kind_refusal_and_the_sets_audience_rules(): void
    {
        $scorer = new EvalScorer();
        $item = ['audience' => 'consultant', 'expect' => ['must_mention' => ['biometric', 'review|decision'], 'kind' => 'data_table|data_tables']];

        $this->assertSame(['pass' => true, 'failures' => []],
            $scorer->score($item, ['content' => 'After biometrics the file goes to review.', 'kind' => 'data_table']));

        $result = $scorer->score($item, ['content' => 'Ask your consultant.', 'kind' => null], ['consultant' => ['ask your consultant']]);
        $this->assertFalse($result['pass']);
        $this->assertContains('forbidden: ask your consultant', $result['failures']);
        $this->assertContains('kind: wanted data_table|data_tables, got text', $result['failures']);

        $this->assertTrue($scorer->score(['expect' => ['refuses' => true]], ['content' => 'I can’t share that.'])['pass']);
    }

    public function test_the_command_runs_the_set_through_the_bound_driver_and_writes_a_report(): void
    {
        $file = sys_get_temp_dir().'/ai-eval-'.uniqid().'.json';
        $out = $file.'.out.json';
        file_put_contents($file, json_encode(['forbid_for_audiences' => ['admin' => ['your consultant']], 'items' => [
            ['id' => 'a-1', 'audience' => 'admin', 'prompt' => 'Q1', 'expect' => ['must_mention' => ['yes']]],
            ['id' => 'a-2', 'audience' => 'admin', 'prompt' => 'Q2', 'expect' => []],
            ['id' => 'c-1', 'audience' => 'client', 'prompt' => 'Q3', 'expect' => []],
        ]]));
        $this->app->instance(EvalDriver::class, new class implements EvalDriver {
            public function answer(array $item, array $set): ?array
            {
                return $item['id'] === 'a-2' ? ['content' => 'Ask your consultant.'] : ['content' => 'yes'];
            }
        });

        $this->artisan('ai:eval', ['--file' => $file, '--out' => $out, '--only' => 'admin'])->assertSuccessful();

        $report = json_decode((string) file_get_contents($out), true);
        $this->assertSame(2, $report['total'], '--only filters by audience');
        $this->assertSame(1, $report['passed']);
        $this->assertSame(['forbidden: your consultant'], $report['items'][1]['failures']);
        @unlink($file);
        @unlink($out);
    }
}
