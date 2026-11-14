<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Terminal\CheckpointStore;
use Shirahcan\AiClient\Laravel\Terminal\ConsoleAsk;
use Shirahcan\AiClient\Laravel\Terminal\RunLog;
use Shirahcan\AiClient\Laravel\Terminal\SessionCompactor;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSessions;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSubject;
use Throwable;

/** A Stop, as a product's run machinery throws it. */
class ConsoleAskTestStop extends RuntimeException {}

/**
 * The console's ask lifecycle in the kit (1.67.0, shared console C1b).
 *
 * ⚠ WHAT THIS GUARDS: the ask is recorded and the rewind point taken BEFORE any work; a plan takes no rewind
 * point; a finished Build records the product's outcome (changed, parts, findings, level) with the rewind
 * point; a Stop is recorded as a stop and a failure as a failure, and both still reach the caller; with no
 * session the ask is not a console ask at all.
 */
class ConsoleAskTest extends TestCase
{
    private FakeAiClient $ai;

    /** @var list<array<string, mixed>> */
    private array $points = [];

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
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
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
                return ['design_chars' => 4000, 'budget_tokens' => 2000];
            }
        });
    }

    private function ask(): ConsoleAsk
    {
        $test = $this;
        $store = new class($test) implements CheckpointStore
        {
            public function __construct(private readonly ConsoleAskTest $test) {}

            public function rewindPoint(string $kind, string $id, array $design, ?string $sessionId, ?string $userId): ?string
            {
                return $this->test->recordPoint(compact('kind', 'id', 'design', 'sessionId', 'userId'));
            }

            public function point(string $kind, string $id, string $pointId): ?array
            {
                return null;
            }
        };

        return new class($this->app->make(TerminalSessions::class), $store, $this->app->make(SessionCompactor::class)) extends ConsoleAsk
        {
            protected function budget(): int
            {
                return 2000;
            }

            protected function outcomeOf(array $console, array $result): array
            {
                $data = (array) ($result['data'] ?? []);

                return [
                    'ok' => ! ($data['unavailable'] ?? false),
                    'changed' => ($data['changed'] ?? true) !== false,
                    'reply' => (string) ($result['summary'] ?? ''),
                    'findings' => array_values((array) ($data['findings'] ?? [])),
                    'changes' => ['changes' => [['uid' => 'h1', 'label' => 'the hero', 'how' => 'changed']], 'total' => 1],
                ];
            }

            protected function isStop(Throwable $e): bool
            {
                return $e instanceof ConsoleAskTestStop;
            }
        };
    }

    /** @param array<string, mixed> $point */
    public function recordPoint(array $point): string
    {
        $this->points[] = $point;

        return 'cp-'.count($this->points);
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: string} the console block, the space and the session */
    private function begin(string $mode = 'build', array $extra = []): array
    {
        $user = new GenericUser(['id' => 'u-1', 'name' => 'Bukky']);
        $this->actingAs($user);
        $space = $this->ai->spaces()->resolve('porter', 'user', 'u-1', null)['id'];
        $session = $this->app->make(TerminalSessions::class)->start($space, 'site_page:p1')['id'];
        $request = Request::create('/x', 'POST', ['session_id' => $session, 'console_target' => 'the hero', ...$extra]);
        $request->setUserResolver(fn () => $user);

        $console = $this->ask()->begin($request, 'site_page', 'p1', [['uid' => 'h1']], 'Make the hero shorter', $mode);

        return [$console, $space, $session];
    }

    /** @return list<array<string, mixed>> */
    private function turns(string $space, string $session): array
    {
        return $this->app->make(TerminalSessions::class)->open($space, $session, 'site_page:p1')['turns'];
    }

    public function test_with_no_session_the_ask_is_not_a_console_ask(): void
    {
        $this->assertNull($this->ask()->begin(Request::create('/x', 'POST'), 'site_page', 'p1', [], 'Hi'));
        $this->assertSame([], $this->points);
    }

    public function test_a_build_records_the_ask_first_and_the_products_outcome_after(): void
    {
        [$console, $space, $session] = $this->begin('build', ['level' => 'pro', 'effort' => 'high']);

        $this->assertSame('cp-1', $console['checkpoint_id'], 'the rewind point is taken before any work');
        $this->assertSame(['site_page', 'p1', [['uid' => 'h1']], $session, 'u-1'], array_values($this->points[0]));
        $this->assertSame(['pro', 'high'], [$console['level'], $console['effort']]);
        $this->assertSame(['user'], array_column($this->turns($space, $session), 'role'), 'the ask is recorded before the work');

        $log = new RunLog(fn () => null);
        $this->ask()->around($console, $log, 'run-1', function () use ($log) {
            $log->step('read', 'Reading the page');

            return ['summary' => 'I shortened the hero.', 'data' => ['changed' => true]];
        });

        $answer = $this->turns($space, $session)[1];
        $this->assertSame('I shortened the hero.', $answer['body']);
        $this->assertSame('cp-1', $answer['structured']['checkpoint_id']);
        $this->assertSame('run-1', $answer['structured']['run_id']);
        $this->assertTrue($answer['structured']['outcome']['changed']);
        $this->assertSame(1, $answer['structured']['outcome']['elements']);
        $this->assertSame('h1', $answer['structured']['outcome']['changes'][0]['uid']);
        $this->assertSame(['Reading the page'], array_column($answer['structured']['steps'], 'text'));
        $this->assertSame('pro', $answer['structured']['level']);
    }

    public function test_a_plan_takes_no_rewind_point_and_records_its_steps(): void
    {
        [$console, $space, $session] = $this->begin('plan');

        $this->assertNull($console['checkpoint_id']);
        $this->assertSame([], $this->points);

        $this->ask()->finishThinking($console, ['summary' => 'Two steps.', 'steps' => ['Shorten', 'Warm up']], false, null);

        $answer = $this->turns($space, $session)[1];
        $this->assertSame('Two steps.', $answer['body']);
        $this->assertFalse($answer['structured']['outcome']['changed']);
        $this->assertSame(['Shorten', 'Warm up'], $answer['structured']['plan']['steps']);
        $this->assertArrayNotHasKey('checkpoint_id', $answer['structured']);
    }

    public function test_a_stop_is_recorded_as_a_stop_and_still_reaches_the_caller(): void
    {
        [$console, $space, $session] = $this->begin();

        try {
            $this->ask()->around($console, new RunLog(fn () => null), 'run-2', fn () => throw new ConsoleAskTestStop('Stopped. Nothing has changed.'));
            $this->fail('the Stop must reach the caller');
        } catch (ConsoleAskTestStop) {
        }

        $outcome = $this->turns($space, $session)[1]['structured']['outcome'];
        $this->assertTrue($outcome['stopped']);
        $this->assertFalse($outcome['changed']);
        $this->assertSame('Stopped. Nothing has changed.', $outcome['reason']);
    }

    public function test_a_failure_is_recorded_as_a_failure_not_a_stop(): void
    {
        [$console, $space, $session] = $this->begin();

        try {
            $this->ask()->around($console, new RunLog(fn () => null), 'run-3', fn () => throw new RuntimeException('The service did not answer.'));
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(ConsoleAskTestStop::class, $e);
        }

        $answer = $this->turns($space, $session)[1];
        $this->assertArrayNotHasKey('stopped', $answer['structured']['outcome']);
        $this->assertSame('The service did not answer.', $answer['structured']['outcome']['reason']);
        $this->assertArrayNotHasKey('checkpoint_id', $answer['structured'], 'nothing changed, so no rewind point is offered');
    }

    public function test_a_database_error_never_reaches_the_transcript_only_a_plain_sentence_does(): void
    {
        [$console, $space, $session] = $this->begin();

        try {
            $this->ask()->around($console, new RunLog(fn () => null), 'run-4', fn () => throw new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' (Connection: mysql, Host: 127.0.0.1, SQL: update ...)"));
            $this->fail('the failure must reach the caller');
        } catch (\PDOException) {
            // The caller still sees the real error (and reports it); only the person's transcript is plain.
        }

        $reason = $this->turns($space, $session)[1]['structured']['outcome']['reason'];
        $this->assertSame('I could not finish this, and nothing was changed. Try again in a moment.', $reason);
        $this->assertStringNotContainsString('SQL', $reason);
    }
}
