<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\FakeAiSpaces;
use Shirahcan\AiClient\Laravel\Terminal\SessionCompactor;
use Shirahcan\AiClient\Laravel\Terminal\SessionWindow;
use Shirahcan\AiClient\Laravel\Terminal\TerminalRoutes;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSessions;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSubject;

/**
 * A console for a studio surface (1.58.0, Porter console).
 *
 * ⚠ WHAT THIS GUARDS: a subject the product refuses is a 404 everywhere; sessions are one subject's
 * and never the chat panel's; the window carries replies, steps and outcomes (not just asks) and
 * leaves rewound and folded turns out; compaction folds without deleting; the brief belongs to the
 * subject; and compaction runs ai-service's shared prompt.
 */
class TerminalTest extends TestCase
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
        $this->app->bind(TerminalSubject::class, fn () => new class implements TerminalSubject
        {
            public function ref(Request $request, string $kind, string $id): ?string
            {
                return $kind === 'site_page' && in_array($id, ['p1', 'p2'], true) ? "site_page:{$id}" : null;
            }

            public function label(string $ref): string
            {
                return $ref === 'site_page:p1' ? 'Home page' : 'Pricing';
            }

            public function sizing(string $ref): array
            {
                return ['design_chars' => 4000, 'budget_tokens' => 2000];
            }
        });
    }

    private function as(string $id): static
    {
        return $this->actingAs(new GenericUser(['id' => $id, 'name' => 'Bukky']));
    }

    private function spaces(): FakeAiSpaces
    {
        return $this->ai->spaces();
    }

    private function sessions(): TerminalSessions
    {
        return $this->app->make(TerminalSessions::class);
    }

    private function spaceOf(string $user): string
    {
        return $this->spaces()->resolve('porter', 'user', $user, null)['id'];
    }

    /** One ask and its answer, recorded the way a Build records them. */
    private function turn(string $space, string $session, string $ask, array $outcome, ?string $reply = 'Done.', array $steps = []): void
    {
        $this->sessions()->appendAsk($space, $session, $ask, ['target' => null]);
        $this->sessions()->appendAnswer($space, $session, $reply, $steps, $outcome);
    }

    public function test_a_subject_the_product_refuses_is_not_found_on_every_route(): void
    {
        $this->getJson('/v1/admin/console/site_page/p1')->assertNotFound();

        foreach (['/v1/admin/console/site_page/other', '/v1/admin/console/studio_component/p1'] as $url) {
            $this->as('u-1')->getJson($url)->assertNotFound();
            $this->as('u-1')->postJson($url.'/sessions')->assertNotFound();
            $this->as('u-1')->putJson($url.'/brief', ['text' => 'x'])->assertNotFound();
        }

        $this->assertSame([], $this->spaces()->calls);
    }

    public function test_the_console_opens_empty_then_holds_one_subjects_sessions_out_of_the_chat_list(): void
    {
        $first = $this->as('u-1')->getJson('/v1/admin/console/site_page/p1')->assertOk()->json('data');
        $this->assertNull($first['session']);
        $this->assertSame('Home page', $first['subject']['label']);

        $started = $this->as('u-1')->postJson('/v1/admin/console/site_page/p1/sessions', ['title' => 'Build the home page'])->assertOk()->json('data');
        $space = $this->spaceOf('u-1');
        $this->turn($space, $started['session']['id'], 'Add a hero', ['changed' => true, 'elements' => 1]);
        $this->as('u-1')->postJson('/v1/admin/console/site_page/p2/sessions')->assertOk();

        $open = $this->as('u-1')->getJson('/v1/admin/console/site_page/p1')->assertOk()->json('data');
        $this->assertSame($started['session']['id'], $open['session']['id']);
        $this->assertSame(['user', 'assistant'], array_column($open['turns'], 'role'));
        $this->assertSame(['Add a hero'], $open['history']);
        $this->assertCount(1, $this->as('u-1')->getJson('/v1/admin/console/site_page/p1/sessions')->json('data.sessions'));

        // The chat panel's own list never sees a console session.
        $this->assertSame([], $this->spaces()->conversations($space)['conversations']);
        $this->assertSame([], $this->spaces()->inputHistory($space));
    }

    public function test_a_session_id_from_another_subject_or_person_does_not_open(): void
    {
        $p2 = $this->as('u-1')->postJson('/v1/admin/console/site_page/p2/sessions')->json('data.session.id');
        $this->as('u-1')->getJson("/v1/admin/console/site_page/p1/sessions/{$p2}")->assertNotFound();
        $this->as('u-2')->getJson("/v1/admin/console/site_page/p2/sessions/{$p2}")->assertNotFound();
    }

    public function test_the_window_carries_replies_steps_and_outcomes_and_leaves_rewound_turns_out(): void
    {
        $space = $this->spaceOf('u-1');
        $session = $this->sessions()->start($space, 'site_page:p1')['id'];
        $this->turn($space, $session, 'Make the hero dark', ['changed' => true, 'elements' => 1, 'kept' => 1], 'Darker hero.', [['kind' => 'write', 'text' => 'Writing the hero', 'ms' => 900, 'detail' => '1 element']]);
        $this->turn($space, $session, 'Add a FAQ from vanhack.com', ['changed' => false, 'reason' => 'The site refused the visit.'], null);
        $this->turn($space, $session, 'Undo the colour', ['changed' => true, 'elements' => 1]);

        $read = $this->sessions()->open($space, $session, 'site_page:p1');
        $window = SessionWindow::build($read['session'], $read['turns'], ['text' => 'Audience: employers.']);

        $this->assertSame('Audience: employers.', $window['brief']);
        $this->assertSame(['Writing the hero (1 element)'], $window['recent'][0]['steps']);
        $this->assertStringContainsString('Edits the person made meanwhile were kept', $window['recent'][0]['outcome']);
        $this->assertSame('Could not finish: The site refused the visit.', $window['recent'][1]['outcome']);

        $this->sessions()->markRewound($space, $session, 'site_page:p1', $read['turns'][4]['id']);
        $after = $this->sessions()->open($space, $session, 'site_page:p1');
        $this->assertSame(['Make the hero dark', 'Add a FAQ from vanhack.com'], array_column(SessionWindow::build($after['session'], $after['turns'], null)['recent'], 'ask'));
    }

    public function test_thinking_is_kept_whole_for_the_person_and_left_out_of_the_next_asks_window(): void
    {
        $space = $this->spaceOf('u-1');
        $session = $this->sessions()->start($space, 'site_page:p1')['id'];
        $thinking = "They want a Q&A below the last section.\n\n".str_repeat('I will keep every other section exactly as it is. ', 40);

        $this->sessions()->appendAsk($space, $session, 'Add a Q&A', ['answers' => [['question' => 'How many questions?', 'answer' => 'Five']]]);
        $this->sessions()->appendAnswer($space, $session, 'Added a Q&A.', [
            ['kind' => 'think', 'text' => 'Thinking', 'detail' => $thinking],
            ['kind' => 'write', 'text' => 'Writing the FAQ', 'detail' => str_repeat('x', 500)],
        ], ['changed' => true], [
            'level' => 'expert', 'served_level' => 'pro', 'effort' => 'high',
            'findings' => [['kind' => 'gap', 'text' => 'The page has no contact link.'], ['kind' => 'nonsense', 'text' => 'dropped']],
            'questions' => [['text' => 'Which tone?', 'options' => [['label' => 'Warm', 'description' => 'Friendly'], 'Formal']]],
        ]);

        $turns = $this->sessions()->open($space, $session, 'site_page:p1')['turns'];
        $answer = $turns[1]['structured'];

        $this->assertSame(trim($thinking), $answer['steps'][0]['detail'], 'kept whole, line breaks and all');
        $this->assertSame(['expert', 'pro', 'high'], [$answer['level'], $answer['served_level'], $answer['effort']]);
        $this->assertSame([['kind' => 'gap', 'text' => 'The page has no contact link.']], $answer['findings']);
        $this->assertSame(['label' => 'Formal', 'description' => null], $answer['questions'][0]['options'][1]);
        $this->assertSame('Five', $turns[0]['structured']['answers'][0]['answer']);

        $window = SessionWindow::build($this->sessions()->open($space, $session, 'site_page:p1')['session'], $turns, null);
        $this->assertCount(1, $window['recent'][0]['steps'], 'the thinking is not handed back');
        $this->assertLessThan(260, mb_strlen($window['recent'][0]['steps'][0]));
        $this->assertSame(['Gap: The page has no contact link.'], $window['recent'][0]['findings']);
    }

    public function test_size_is_reported_by_part_and_compaction_is_due_only_with_something_to_fold(): void
    {
        $window = ['brief' => str_repeat('b', 400), 'summary' => null, 'nearby' => ['x -> y'], 'recent' => []];
        $size = SessionWindow::size($window, 6000, 2000);

        $this->assertSame(100, $size['parts']['brief']);
        $this->assertSame(1500, $size['parts']['design']);
        $this->assertSame(array_sum($size['parts']), $size['used']);
        $this->assertTrue(SessionWindow::needsCompaction($window, $size));
        $this->assertFalse(SessionWindow::needsCompaction(['nearby' => []] + $window, $size));
    }

    public function test_compact_folds_older_turns_into_the_summary_without_deleting_them(): void
    {
        $space = $this->spaceOf('u-1');
        $session = $this->as('u-1')->postJson('/v1/admin/console/site_page/p1/sessions')->json('data.session.id');
        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $ask) {
            $this->turn($space, $session, $ask, ['changed' => true, 'elements' => 1]);
        }
        $this->ai->queue(['summary' => 'Built five sections; the person wants a calm tone.']);

        $data = $this->as('u-1')->postJson("/v1/admin/console/site_page/p1/sessions/{$session}/compact", ['focus' => 'tone'])->assertOk()->json('data');

        $this->assertTrue($data['compacted']);
        $this->assertSame(4, $data['session']['summary']['turns_folded']);
        $this->assertCount(10, $data['turns']);
        $call = end($this->ai->log->calls);
        $this->assertSame(SessionCompactor::TASK, $call['task']);
        $this->assertSame('tone', $call['payload']['vars']['focus']);
        $this->assertStringContainsString('"ask": "Two"', $call['payload']['vars']['turns']);
        $this->assertStringNotContainsString('"ask": "Three"', $call['payload']['vars']['turns']);

        $read = $this->sessions()->open($space, $session, 'site_page:p1');
        $window = SessionWindow::build($read['session'], $read['turns'], null);
        $this->assertSame(['Three', 'Four', 'Five'], array_column($window['recent'], 'ask'));
        $this->assertSame([], $window['nearby']);
        $this->assertSame('Built five sections; the person wants a calm tone.', $window['summary']['text']);
    }

    public function test_the_brief_belongs_to_the_subject_and_outlives_a_new_session(): void
    {
        $this->as('u-1')->putJson('/v1/admin/console/site_page/p1/brief', ['text' => 'Employers are the audience.'])->assertOk()
            ->assertJsonPath('data.brief.by', 'Bukky');
        $this->as('u-2')->postJson('/v1/admin/console/site_page/p1/sessions')->assertOk()
            ->assertJsonPath('data.brief.text', 'Employers are the audience.');

        $this->as('u-1')->putJson('/v1/admin/console/site_page/p1/brief', ['text' => ''])->assertOk()->assertJsonPath('data.brief', null);
    }

    public function test_compaction_runs_ai_services_shared_prompt(): void
    {
        // Shared prompts publish with ai-service itself, so no product has to carry a copy.
        $this->assertStringStartsWith('shared.', SessionCompactor::TASK);
    }
}
