<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Terminal\RunLog;
use Shirahcan\AiClient\Laravel\Terminal\RunOptions;
use Shirahcan\AiClient\Laravel\Terminal\SessionUsage;

/**
 * The console's run options and run log in the kit (1.66.0, C1a of the shared console).
 *
 * ⚠ WHAT THIS GUARDS: outside a scope the client is untouched (every other call in a product is what it
 * was); a scope applies level, effort and the session's cost tag; the lowest served level is kept; a
 * scope is restored even when the work throws (a queue worker never carries it into the next job). The
 * log's steps are add-only and timed, thinking becomes one step, the product's phrase names the parts,
 * and every save carries the steps beside the run's own progress keys.
 */
class RunOptionsAndLogTest extends TestCase
{
    public function test_outside_a_scope_the_client_is_untouched(): void
    {
        $fake = new FakeAiClient;

        $this->assertSame($fake, RunOptions::applyTo($fake));
        $this->assertSame(['level' => null, 'effort' => null], RunOptions::current());
        $this->assertNull(RunOptions::servedLevel());
    }

    public function test_a_scope_applies_level_effort_and_the_session_tag(): void
    {
        $fake = (new FakeAiClient)->queue('words');

        RunOptions::during('expert', 'high', function () use ($fake) {
            $this->assertSame(['level' => 'expert', 'effort' => 'high'], RunOptions::current());

            RunOptions::applyTo($fake)->generateText('t', 's', 'p');
        }, 'sess-1');

        $this->assertSame(['expert', 'high'], [$fake->log->level, $fake->log->effort]);
        $this->assertSame(SessionUsage::key('sess-1', 'expert'), $fake->correlation()['_key'] ?? null);
        $this->assertSame(['level' => null, 'effort' => null], RunOptions::current(), 'the scope closes');
    }

    public function test_an_unknown_level_or_effort_is_ignored(): void
    {
        RunOptions::during('ultra', 'max', function () {
            $this->assertSame(['level' => null, 'effort' => null], RunOptions::current());
        });
    }

    public function test_the_lowest_served_level_is_kept(): void
    {
        $served = RunOptions::during('expert', null, function () {
            RunOptions::served('expert');
            RunOptions::served('pro');
            RunOptions::served('expert');
            RunOptions::served('nonsense');

            return RunOptions::servedLevel();
        });

        $this->assertSame('pro', $served);
    }

    public function test_the_scope_is_restored_when_the_work_throws(): void
    {
        try {
            RunOptions::during('pro', 'low', fn () => throw new RuntimeException('stopped'));
        } catch (RuntimeException) {
        }

        $this->assertSame(['level' => null, 'effort' => null], RunOptions::current());
    }

    public function test_steps_are_add_only_and_carry_the_runs_own_progress(): void
    {
        $saved = [];
        $log = new RunLog(function (array $progress) use (&$saved) {
            $saved[] = $progress;
        });

        $log->step('read', 'Reading the page');
        $log->progress(['elements' => [['uid' => 'a']], 'title' => 'Draft']);
        $steps = $log->finish();

        $this->assertSame(['Reading the page'], array_column($steps, 'text'));
        $this->assertIsInt($steps[0]['ms'], 'a finished step is timed');
        $last = end($saved);
        $this->assertSame('Draft', $last['title'], 'the run\'s own keys ride beside the steps');
        $this->assertCount(1, $last['steps']);
        $this->assertNull($last['thinking']);
    }

    public function test_thinking_becomes_one_step_when_the_run_moves_on(): void
    {
        $saved = [];
        $log = new RunLog(function (array $progress) use (&$saved) {
            $saved[] = $progress;
        });

        $log->thinking('First I read');
        $log->thinking('First I read the brief.');
        $log->step('write', 'Writing the hero');
        $steps = $log->finish();

        $this->assertSame(['think', 'write'], array_column($steps, 'kind'));
        $this->assertSame('First I read the brief.', $steps[0]['detail']);
    }

    public function test_the_products_phrase_names_the_parts_and_each_part_gets_one_line(): void
    {
        $log = new RunLog(fn () => null, fn (string $name) => $name === 'box' ? 'a section' : 'the '.$name);

        $log->progress(['writing' => 'box', 'elements' => []]);
        $log->progress(['writing' => 'box', 'elements' => []]);
        $log->progress(['writing' => 'faq', 'elements' => [[]]]);

        $this->assertSame(['Writing a section', 'Writing the faq'], array_column($log->finish(), 'text'));
        $this->assertSame('the process timeline', (new RunLog(fn () => null))->phrase('process_timeline'));
    }

    public function test_steps_stop_at_the_cap(): void
    {
        $log = new RunLog(fn () => null);

        for ($i = 0; $i < RunLog::MAX + 5; $i++) {
            $log->step('read', "Step {$i}");
        }

        $this->assertCount(RunLog::MAX, $log->finish());
    }
}
