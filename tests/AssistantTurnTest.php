<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Assistant\ToolCatalog;
use Shirahcan\AiClient\Assistant\ToolContract;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;

/**
 * The assistant harness: plan, use the product's tools, answer.
 *
 * ⚠ THE ASSERTION THIS FILE EXISTS FOR is that a tool the viewer may not use is
 * never EXECUTED - not even when the model asks for it a second time with
 * different arguments, which is exactly how an authorise-once design is
 * defeated.
 */
class AssistantTurnTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    /** @param array<int, Response> $responses */
    private function client(array $responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new AiClient(
            baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']),
        );
    }

    /** A step the model answers with. @param array<string, mixed> $decision */
    private function step(array $decision): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'result'     => $decision,
            'provider'   => 'gemini',
            'model'      => 'gemini-2.5-flash',
            'latency_ms' => 5,
            'tokens'     => ['in' => 10, 'out' => 10],
            'attempts'   => [],
        ]));
    }

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    /*
    |--------------------------------------------------------------------------
    | The catalogue
    |--------------------------------------------------------------------------
    */

    public function test_a_tool_name_the_model_would_mangle_is_refused_at_registration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolCatalog([$this->tool('Atlas Query')]);
    }

    public function test_two_tools_with_one_name_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolCatalog([$this->tool('atlas_query'), $this->tool('atlas_query')]);
    }

    /**
     * ⚠ EVERY TOOL IS DESCRIBED, INCLUDING ONES THIS VIEWER MAY NOT USE. A model
     * that cannot see a tool cannot say "you would need X for that", so a client
     * asking for something consultant-only would get a confused non-answer
     * rather than a straight one. Describing grants nothing; execution is where
     * the refusal happens.
     */
    public function test_the_catalogue_describes_every_tool(): void
    {
        $catalog = new ToolCatalog([
            $this->tool('atlas_query'),
            $this->tool('consultant_only', authorized: false),
        ]);

        $this->assertSame(['atlas_query', 'consultant_only'], $catalog->names());
        $this->assertCount(2, $catalog->describe());
    }

    /*
    |--------------------------------------------------------------------------
    | The loop
    |--------------------------------------------------------------------------
    */

    public function test_a_turn_that_needs_no_tool_answers_in_one_step(): void
    {
        $client = $this->client([$this->step(['action' => 'answer', 'text' => 'Two cases are open.'])]);

        $answer = $client->assistant('porter', 'consultant', 'You are helpful.')
            ->withTools(new ToolCatalog([$this->tool('atlas_query')]))
            ->as('the-user')
            ->ask('how many cases are open?');

        $this->assertSame('Two cases are open.', $answer->text);
        $this->assertSame(1, $answer->steps);
        $this->assertFalse($answer->exhausted);
        $this->assertFalse($answer->usedAnyTool());

        // The persona rode through to the service, from the one choke point.
        $this->assertSame('porter', $this->body()['persona']);
        $this->assertSame('consultant', $this->body()['audience']);
    }

    public function test_it_runs_a_tool_and_answers_from_the_result(): void
    {
        $ran = [];

        $catalog = new ToolCatalog([
            $this->tool('atlas_query', onRun: function (array $args) use (&$ran) {
                $ran[] = $args;

                return ['open_cases' => 3];
            }),
        ]);

        $client = $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'atlas_query', 'arguments' => ['entity' => 'case']]]),
            $this->step(['action' => 'answer', 'text' => 'You have three open cases.']),
        ]);

        $answer = $client->assistant('porter', 'consultant')
            ->withTools($catalog)->as('the-user')
            ->ask('how many cases are open?');

        $this->assertSame('You have three open cases.', $answer->text);
        $this->assertSame(['atlas_query'], $answer->toolsUsed());
        $this->assertSame([['entity' => 'case']], $ran);
        $this->assertSame(2, $answer->steps);

        // The second step carried the decision AND the observation.
        $messages = $this->body(1)['messages'];
        $this->assertStringContainsString('atlas_query', $messages[1]['content']);
        $this->assertStringContainsString('open_cases', $messages[2]['content']);
    }

    /**
     * ⚠ THE CENTRAL SECURITY ASSERTION. A refused tool is NEVER EXECUTED, and
     * asking again with different arguments does not get round it - which is
     * precisely how a design that authorises once per turn is defeated.
     */
    public function test_a_refused_tool_is_never_executed_however_often_it_is_asked_for(): void
    {
        $executions = 0;
        $authorizeCalls = [];

        $catalog = new ToolCatalog([
            $this->tool(
                'consultant_only',
                authorized: false,
                onRun: function () use (&$executions) {
                    $executions++;

                    return ['secret' => 'should never be reached'];
                },
                onAuthorize: function (mixed $viewer, array $args) use (&$authorizeCalls) {
                    $authorizeCalls[] = $args;

                    return false;
                },
            ),
        ]);

        $client = $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'consultant_only', 'arguments' => ['case_id' => 'one']]]),
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'consultant_only', 'arguments' => ['case_id' => 'two']]]),
            $this->step(['action' => 'answer', 'text' => 'I cannot see that from here.']),
        ]);

        $answer = $client->assistant('porter', 'client')
            ->withTools($catalog)->as('a-client')
            ->ask('show me the consultant notes');

        $this->assertSame(0, $executions, 'a refused tool was executed');
        $this->assertSame('I cannot see that from here.', $answer->text);
        $this->assertSame(['consultant_only'], $answer->refusedTools());
        $this->assertSame([], $answer->toolsUsed());

        /*
         * ⚠ AUTHORISED TWICE, WITH THE ACTUAL ARGUMENTS EACH TIME. A check made
         * once against the first arguments is not a check: the second request
         * named a different case, and that is a different question about
         * permission.
         */
        $this->assertSame([['case_id' => 'one'], ['case_id' => 'two']], $authorizeCalls);
    }

    /** The viewer reaches `authorize()` unchanged. */
    public function test_the_viewer_is_handed_to_authorize(): void
    {
        $seen = null;
        $viewer = new \stdClass();

        $catalog = new ToolCatalog([
            $this->tool('atlas_query', onAuthorize: function (mixed $v) use (&$seen) {
                $seen = $v;

                return true;
            }),
        ]);

        $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'atlas_query', 'arguments' => []]]),
            $this->step(['action' => 'answer', 'text' => 'done']),
        ])->assistant('porter')->withTools($catalog)->as($viewer)->ask('go');

        $this->assertSame($viewer, $seen);
    }

    /** A hallucinated name is told the real ones rather than a bare error. */
    public function test_an_unknown_tool_is_named_back_with_the_real_ones(): void
    {
        $client = $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'invented_tool', 'arguments' => []]]),
            $this->step(['action' => 'answer', 'text' => 'Answered without it.']),
        ]);

        $answer = $client->assistant('porter')
            ->withTools(new ToolCatalog([$this->tool('atlas_query')]))
            ->as('u')->ask('go');

        $this->assertSame('unknown_tool', $answer->trail[0]['outcome']);
        $this->assertStringContainsString('atlas_query', $this->body(1)['messages'][2]['content']);
    }

    /**
     * ⚠ A TOOL THAT HAS FAILED TWICE IS TOLD TO STOP. The model's instinct after
     * an error is to retry with slightly different arguments, which against a
     * genuinely broken tool is an expensive way to reach the step cap.
     */
    public function test_a_tool_that_fails_twice_is_taken_off_the_table(): void
    {
        $catalog = new ToolCatalog([
            $this->tool('flaky', onRun: fn () => throw new \RuntimeException('database is down')),
        ]);

        $client = $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'flaky', 'arguments' => ['a' => 1]]]),
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'flaky', 'arguments' => ['a' => 2]]]),
            $this->step(['action' => 'answer', 'text' => 'I could not look that up.']),
        ]);

        $answer = $client->assistant('porter')->withTools($catalog)->as('u')->ask('go');

        $this->assertSame('I could not look that up.', $answer->text);
        $this->assertSame('failed', $answer->trail[0]['outcome']);
        $this->assertStringContainsString('database is down', $this->body(1)['messages'][2]['content']);

        $lastMessages = $this->body(2)['messages'];
        $this->assertStringContainsString('failed twice', end($lastMessages)['content']);
    }

    /**
     * ⚠ EXHAUSTED RETURNS NO TEXT, DELIBERATELY. The model never decided to say
     * anything, and presenting its last mid-thought as an answer is how an
     * assistant states half a fact confidently.
     */
    public function test_a_looping_model_stops_at_the_step_cap_with_no_text(): void
    {
        $catalog = new ToolCatalog([$this->tool('atlas_query', onRun: fn () => ['rows' => []])]);

        $client = $this->client(array_fill(0, 4, $this->step([
            'action' => 'use_tool',
            'tool'   => ['name' => 'atlas_query', 'arguments' => []],
        ])));

        $answer = $client->assistant('porter')->withTools($catalog)->as('u')->withSteps(4)->ask('go');

        $this->assertTrue($answer->exhausted);
        $this->assertSame('', $answer->text);
        $this->assertSame(4, $answer->steps);
        $this->assertCount(4, $answer->trail);
    }

    /**
     * ⚠ THE FIRST STEP FAILING THROWS; A LATER ONE DOES NOT. With nothing
     * produced there is no answer to degrade from, so the caller must run its
     * deterministic path. Mid-loop there are tool results the user has already
     * waited for, and throwing those away is the worse trade.
     */
    public function test_the_first_step_failing_throws_so_the_caller_can_degrade(): void
    {
        $this->expectException(AllProvidersFailedException::class);

        $this->client([
            new Response(503, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => 'all_providers_failed', 'message' => 'everything refused',
            ])),
        ])->assistant('porter')->as('u')->ask('go');
    }

    public function test_a_later_step_failing_returns_what_there_is(): void
    {
        $catalog = new ToolCatalog([$this->tool('atlas_query', onRun: fn () => ['rows' => 1])]);

        $client = $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'atlas_query', 'arguments' => []]]),
            new Response(503, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => 'all_providers_failed', 'message' => 'everything refused',
            ])),
        ]);

        $answer = $client->assistant('porter')->withTools($catalog)->as('u')->ask('go');

        $this->assertTrue($answer->exhausted);
        $this->assertSame(['atlas_query'], $answer->toolsUsed());
    }

    /**
     * ⚠ EVERY TOOL RESULT IS RE-SENT ON EVERY LATER STEP, so an unbounded result
     * is an unbounded multiplier. Truncated with a visible marker so the model
     * can say the list was cut rather than presenting part of it as all of it.
     */
    public function test_a_huge_tool_result_is_truncated_visibly(): void
    {
        $catalog = new ToolCatalog([
            $this->tool('atlas_query', onRun: fn () => ['blob' => str_repeat('x', 20000)]),
        ]);

        $client = $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'atlas_query', 'arguments' => []]]),
            $this->step(['action' => 'answer', 'text' => 'ok']),
        ]);

        $client->assistant('porter')->withTools($catalog)->as('u')->ask('go');

        $observation = $this->body(1)['messages'][2]['content'];

        $this->assertLessThan(7000, mb_strlen($observation));
        $this->assertStringContainsString('truncated', $observation);
    }

    /** A turn with no tools at all still works - an assistant may be a reader. */
    public function test_a_turn_with_no_catalogue_still_answers(): void
    {
        $answer = $this->client([$this->step(['action' => 'answer', 'text' => 'hello'])])
            ->assistant('porter')->ask('hi');

        $this->assertSame('hello', $answer->text);
        $this->assertStringNotContainsString('tools', $this->body()['system']);
    }

    /** The tool schemas reach the model in the system prompt. */
    public function test_the_tools_are_described_in_the_system_prompt(): void
    {
        $this->client([$this->step(['action' => 'answer', 'text' => 'x'])])
            ->assistant('porter', null, 'You are Porter.')
            ->withTools(new ToolCatalog([$this->tool('atlas_query')]))
            ->as('u')->ask('go');

        $system = $this->body()['system'];

        $this->assertStringContainsString('You are Porter.', $system);
        $this->assertStringContainsString('atlas_query', $system);
        $this->assertStringContainsString('never invent a tool', $system);
    }

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    */

    /** @param array<int, array<string, mixed>> $events */
    private function sse(array $events): Response
    {
        $body = '';

        foreach ($events as $event) {
            $body .= 'event: '.$event['type']."
".'data: '.json_encode($event['data'] ?? [])."

";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    /**
     * ⚠ NO TOOLS MEANS NO PLANNING CALL. An assistant that only reads is a
     * legitimate shape, and making it pay a structured step to be told "just
     * answer" would be a cost with no decision attached.
     */
    public function test_a_turn_with_no_tools_streams_directly_in_one_call(): void
    {
        $client = $this->client([$this->sse([
            ['type' => 'open', 'data' => ['stream_id' => 'abc']],
            ['type' => 'token', 'data' => ['text' => 'Hello ']],
            ['type' => 'token', 'data' => ['text' => 'there.']],
            ['type' => 'done', 'data' => ['text' => 'Hello there.']],
        ])]);

        $types = [];
        $text = '';

        foreach ($client->assistant('porter')->stream('hi') as $event) {
            $types[] = $event->type;
            $text .= $event->text();
        }

        $this->assertSame(['open', 'token', 'token', 'done'], $types);
        $this->assertSame('Hello there.', $text);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('generate/stream', (string) $this->sent[0]->getUri());
    }

    /**
     * ⚠ THE REPLAY PATH. The planner already wrote the answer and there is no
     * new evidence a second call could write from, so it is replayed rather than
     * bought again - and the consumer cannot tell the difference.
     */
    public function test_a_turn_that_needed_no_tool_replays_instead_of_paying_twice(): void
    {
        $client = $this->client([
            $this->step(['action' => 'answer', 'text' => 'You have three open cases right now.']),
        ]);

        $types = [];
        $text = '';
        $openEvent = null;

        foreach ($client->assistant('porter')->withTools(new ToolCatalog([$this->tool('atlas_query')]))->as('u')->stream('how many?') as $event) {
            $types[] = $event->type;
            $text .= $event->text();

            if ($event->type === 'open') {
                $openEvent = $event;
            }
        }

        $this->assertSame('You have three open cases right now.', $text);
        $this->assertSame('open', $types[0]);
        $this->assertSame('done', $types[count($types) - 1]);

        // ⚠ Several token events, not one: a consumer that animates per token
        // would otherwise jump from nothing to everything.
        $this->assertGreaterThan(1, count(array_filter($types, fn ($t) => $t === 'token')));

        // ⚠ No stream id: there is nothing to cancel, it is already complete.
        $this->assertNull($openEvent?->streamId());

        // And exactly ONE model call was made.
        $this->assertCount(1, $this->sent);
    }

    /** With tools used, the prose IS streamed, from the gathered evidence. */
    public function test_a_turn_that_used_a_tool_streams_the_prose_from_the_evidence(): void
    {
        $catalog = new ToolCatalog([
            $this->tool('atlas_query', onRun: fn () => ['open_cases' => 3]),
        ]);

        $client = $this->client([
            $this->step(['action' => 'use_tool', 'tool' => ['name' => 'atlas_query', 'arguments' => []]]),
            $this->step(['action' => 'answer', 'text' => 'ignored - the prose is streamed next']),
            $this->sse([
                ['type' => 'open', 'data' => ['stream_id' => 'xyz']],
                ['type' => 'token', 'data' => ['text' => 'Three cases are open.']],
                ['type' => 'done', 'data' => ['text' => 'Three cases are open.']],
            ]),
        ]);

        $text = '';
        $streamId = null;

        foreach ($client->assistant('porter')->withTools($catalog)->as('u')->stream('how many?') as $event) {
            $text .= $event->text();
            $streamId ??= $event->streamId();
        }

        $this->assertSame('Three cases are open.', $text);
        $this->assertSame('xyz', $streamId, 'a real stream must expose its id so Stop can work');

        // Two planning steps plus the streamed answer.
        $this->assertCount(3, $this->sent);

        // ⚠ The streamed call carries the tool OBSERVATION, not a summary of it.
        $streamBody = json_decode((string) $this->sent[2]->getBody(), true);
        $this->assertStringContainsString('open_cases', $streamBody['prompt']);
        $this->assertStringContainsString('Do not mention the tools by name', $streamBody['system']);
    }

    /**
     * ⚠ AN EXHAUSTED LOOP IS AN ERROR EVENT, NOT AN EMPTY DONE. A consumer
     * that paints a finished-looking empty answer has told the user the
     * assistant had nothing to say, which is not what happened.
     */
    public function test_an_exhausted_loop_streams_an_error_not_an_empty_answer(): void
    {
        $catalog = new ToolCatalog([$this->tool('atlas_query', onRun: fn () => ['rows' => []])]);

        $client = $this->client(array_fill(0, 2, $this->step([
            'action' => 'use_tool',
            'tool'   => ['name' => 'atlas_query', 'arguments' => []],
        ])));

        $types = [];

        foreach ($client->assistant('porter')->withTools($catalog)->as('u')->withSteps(2)->stream('go') as $event) {
            $types[] = $event->type;
        }

        $this->assertSame(['error'], $types);
    }

    /**
     * ⚠ THE TRAP THIS ASSERTION CLOSES. `text()` promised "empty for anything
     * else" while reading `data['text']` from any event - and a `done` event
     * carries the WHOLE answer there, so the obvious consumer loop produced the
     * answer twice, concatenated, with no error. The consumer that trusted the
     * documentation was the one that got it wrong.
     */
    public function test_only_token_events_carry_text(): void
    {
        $client = $this->client([$this->sse([
            ['type' => 'open', 'data' => ['stream_id' => 'abc']],
            ['type' => 'token', 'data' => ['text' => 'Hello.']],
            ['type' => 'done', 'data' => ['text' => 'Hello.']],
        ])]);

        $accumulated = '';
        $done = null;

        foreach ($client->assistant('porter')->stream('hi') as $event) {
            $accumulated .= $event->text();

            if ($event->type === 'done') {
                $done = $event;
            }
        }

        $this->assertSame('Hello.', $accumulated);
        $this->assertSame('', $done->text(), 'a done event must not answer text()');
        $this->assertSame('Hello.', $done->fullText());
    }

    /** Cancel reaches the service, and a failed cancel never throws. */
    public function test_cancelling_a_stream_tells_the_service(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['cancelled' => true])),
        ]);

        $this->assertTrue($client->cancelStream('xyz'));
        $this->assertSame('DELETE', $this->sent[0]->getMethod());
        $this->assertStringContainsString('generate/stream/xyz', (string) $this->sent[0]->getUri());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function tool(
        string $name,
        bool $authorized = true,
        ?callable $onRun = null,
        ?callable $onAuthorize = null,
    ): ToolContract {
        return new class($name, $authorized, $onRun, $onAuthorize) implements ToolContract
        {
            public function __construct(
                private string $toolName,
                private bool $authorized,
                private $onRun,
                private $onAuthorize,
            ) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'A test tool called '.$this->toolName.'.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => ['entity' => ['type' => 'string']]];
            }

            public function authorize(mixed $viewer, array $args): bool
            {
                if ($this->onAuthorize !== null) {
                    return (bool) ($this->onAuthorize)($viewer, $args);
                }

                return $this->authorized;
            }

            public function run(array $args): mixed
            {
                if ($this->onRun !== null) {
                    return ($this->onRun)($args);
                }

                return ['ok' => true];
            }
        };
    }
}
