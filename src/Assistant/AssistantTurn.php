<?php

namespace Shirahcan\AiClient\Assistant;

use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Throwable;

/**
 * One assistant turn: plan, use tools, answer.
 *
 * ⚠ THE LOOP LIVES HERE AND RUNS IN THE PRODUCT'S PROCESS. ai-service decides
 * WHAT to do next; this class decides whether the viewer may do it and then does
 * it. The alternative - the service driving the loop and calling back into each
 * product - is the tidier diagram and the wrong trade: it would give the box
 * holding every AI provider key in the estate the ability to act AS A USER
 * inside every product, taking the blast radius of a compromise from "the AI
 * keys" to "every product, as anybody". This estate has already had a root
 * compromise. It would also give each product's permission model two enforcers.
 *
 * ⚠ TOOL USE IS EXPRESSED THROUGH A FORCED SCHEMA, NOT A PROVIDER'S NATIVE TOOL
 * API, and that is deliberate. The service is provider-neutral: the same turn
 * has to work on Gemini, Groq, Mistral and OpenRouter, whose tool-calling
 * dialects differ and some of whose free tiers do not offer one at all. A
 * structured step works everywhere and keeps the service from ever learning what
 * a Portify tool is.
 *
 * Shape:
 *
 *     $answer = $ai->assistant('porter', 'consultant')
 *         ->withTools($catalog)
 *         ->as($user)
 *         ->withKnowledge()
 *         ->ask('what is outstanding on the Garcia file?');
 *
 *     $answer->text;            // the reply
 *     $answer->toolsUsed();     // ['atlas_query']
 *     $answer->exhausted;       // ran out of steps rather than finishing
 */
class AssistantTurn
{
    /**
     * ⚠ BOUNDED, AND LOW. Each step is a paid model call that re-sends every
     * previous tool result, so cost grows faster than linearly. Four is enough
     * for "look something up, look up a second thing, answer" and small enough
     * that a model stuck in a loop costs four calls rather than a bill.
     */
    private int $maxSteps = 4;

    /**
     * ⚠ A WALL-CLOCK CEILING AS WELL AS A STEP CEILING, because the two bound
     * different failures: the step cap stops a loop, this stops a turn whose
     * individual calls are each slow enough to blow an inline request's budget
     * while never looping at all.
     */
    private float $budgetSeconds = 45.0;

    /**
     * How much of one tool's result may go back to the model.
     *
     * ⚠ EVERY RESULT IS RE-SENT ON EVERY LATER STEP, so an unbounded result is
     * an unbounded multiplier: one tool returning a 200KB row set turns a
     * three-step turn into three 200KB prompts. Truncated with a visible marker
     * rather than silently, so the model can say the list was cut.
     */
    private int $maxResultChars = 6000;

    private ?ToolCatalog $tools = null;
    private mixed $viewer = null;
    private bool $withKnowledge = false;
    private ?string $knowledgeAudience = null;

    public function __construct(
        private AiClient $client,
        private string $task,
        private string $system,
    ) {}

    public function withTools(ToolCatalog $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Who is asking. Passed to every `authorize()` call.
     *
     * ⚠ WITHOUT IT, EVERY TOOL IS AUTHORISED AGAINST NULL. A product whose
     * `authorize()` reads `$viewer->can(...)` would then throw on every step,
     * and one that returns true for an unknown viewer would be worse. State it.
     */
    public function as(mixed $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * Ground the turn in this product's knowledge index before the first step.
     *
     * ⚠ THE AUDIENCE IS A HARD FILTER ON THE SERVICE SIDE, so pass the asking
     * user's audience. Omitted, only audience-blind entries are retrieved,
     * which is the narrow (and safe) reading rather than everything.
     */
    public function withKnowledge(?string $audience = null): static
    {
        $this->withKnowledge = true;
        $this->knowledgeAudience = $audience;

        return $this;
    }

    public function withSteps(int $maxSteps): static
    {
        $this->maxSteps = max(1, min(8, $maxSteps));

        return $this;
    }

    public function withBudget(float $seconds): static
    {
        $this->budgetSeconds = max(5.0, min(180.0, $seconds));

        return $this;
    }

    /**
     * Run the turn.
     *
     * @param  string|array<int, array<string, mixed>>  $input  a question, or a
     *   provider-neutral message list for a continuing conversation
     *
     * @throws AllProvidersFailedException when the FIRST step cannot be made at
     *   all - there is no answer to degrade from, so the caller's deterministic
     *   path should run instead.
     */
    public function ask(string|array $input): AssistantAnswer
    {
        $messages = is_string($input)
            ? [['role' => 'user', 'content' => $input]]
            : array_values($input);

        $system = $this->systemPrompt($this->firstUserText($messages));
        $deadline = microtime(true) + $this->budgetSeconds;

        $trail = [];
        $steps = 0;
        $provider = null;
        /** @var array<string, int> how many times each tool has FAILED this turn */
        $failures = [];

        while ($steps < $this->maxSteps) {
            if (microtime(true) >= $deadline) {
                return $this->exhaustedAnswer($messages, $trail, $steps, $provider);
            }

            $steps++;

            try {
                $result = $this->client->generateStructured(
                    task: $this->task,
                    system: $system,
                    messages: $messages,
                    schema: $this->stepSchema(),
                    schemaName: 'assistant_step',
                    requiredKeys: ['action'],
                );
            } catch (AllProvidersFailedException $e) {
                /*
                 * ⚠ THE FIRST STEP FAILING IS DIFFERENT FROM A LATER ONE. With
                 * nothing produced yet there is no answer to give, so the caller
                 * must know to run its deterministic path; mid-loop, we already
                 * have tool results and the model's earlier reasoning, so the
                 * honest move is to stop and hand back what there is rather than
                 * throw away work the user has already waited for.
                 */
                if ($steps === 1) {
                    throw $e;
                }

                return $this->exhaustedAnswer($messages, $trail, $steps, $provider);
            }

            $provider = $result->provider ?? $provider;
            $decision = $result->array();
            $action = (string) ($decision['action'] ?? 'answer');

            if ($action !== 'use_tool') {
                return new AssistantAnswer(
                    text: trim((string) ($decision['text'] ?? '')),
                    trail: $trail,
                    steps: $steps,
                    exhausted: false,
                    messages: $messages,
                    provider: $provider,
                );
            }

            $name = (string) ($decision['tool']['name'] ?? '');
            $args = (array) ($decision['tool']['arguments'] ?? []);

            [$observation, $entry] = $this->dispatch($name, $args, $failures);
            $trail[] = $entry;

            /*
             * The decision and its observation both go into the transcript, so
             * the next step sees what it asked for and what came back. Recorded
             * as plain roles because the service is provider-neutral and each
             * adapter projects them.
             */
            $messages[] = ['role' => 'assistant', 'content' => 'I will use '.$name.' with '.$this->encode($args)];
            $messages[] = ['role' => 'user', 'content' => $observation];

            /*
             * ⚠ A TOOL THAT HAS FAILED TWICE IS NOT OFFERED A THIRD TIME. The
             * model's instinct after an error is to retry with slightly
             * different arguments, which against a genuinely broken tool is an
             * expensive way to produce the same error until the step cap. Two
             * attempts is enough to recover from a bad argument.
             */
            if (($failures[$name] ?? 0) >= 2) {
                $messages[] = [
                    'role' => 'user',
                    'content' => "The tool {$name} has failed twice. Do not use it again; answer with what you have.",
                ];
            }
        }

        return $this->exhaustedAnswer($messages, $trail, $steps, $provider);
    }

    /**
     * Run the turn and STREAM the answer.
     *
     * Yields {@see \Shirahcan\AiClient\AiStreamEvent}s, so one consumer handles
     * both shapes. What it costs depends on the path, and the three are
     * deliberately different:
     *
     *   | path                       | model calls | streamed by the provider |
     *   |----------------------------|-------------|--------------------------|
     *   | no tools registered        | 1           | yes                      |
     *   | tools, none needed         | 1           | no (replayed)            |
     *   | tools, some used           | 2 + steps   | yes                      |
     *
     * ⚠ THE MIDDLE ROW IS THE INTERESTING ONE. When the planner decides it can
     * answer without a tool it has ALREADY WRITTEN the answer, and there is no
     * new evidence a second call could write from - so the text is replayed as
     * tokens instead of being bought again. The consumer cannot tell the
     * difference, and nobody pays twice for one sentence.
     *
     * ⚠ A REPLAYED STREAM HAS NO STREAM ID, so `streamId()` is null on its
     * `open` event. There is nothing to cancel: it is already complete. A
     * consumer that stores the id to enable its Stop button must treat null as
     * "no stop needed" rather than as an error.
     *
     * ⚠ AND `DISCARD` STILL MATTERS ON THE STREAMED PATHS. The waterfall can
     * fail a rung after emitting tokens, and a consumer that keeps appending
     * shows two half-answers glued together - which reads as finished prose.
     *
     * @param  string|array<int, array<string, mixed>>  $input
     * @return iterable<\Shirahcan\AiClient\AiStreamEvent>
     *
     * @throws AllProvidersFailedException when nothing could be produced at all
     */
    public function stream(string|array $input): iterable
    {
        $messages = is_string($input)
            ? [['role' => 'user', 'content' => $input]]
            : array_values($input);

        $question = $this->firstUserText($messages);

        /*
         * ⚠ NO TOOLS MEANS NO PLANNING CALL. An assistant that only reads is a
         * legitimate and common shape (a public-site answerer, a summariser),
         * and making it pay a structured step to be told "just answer" would be
         * a cost with no decision attached.
         */
        if ($this->tools === null || $this->tools->isEmpty()) {
            yield from $this->client->generateStream(
                task: $this->task,
                system: $this->systemPrompt($question),
                prompt: $this->flatten($messages),
            );

            return;
        }

        $answer = $this->ask($messages);

        if ($answer->exhausted) {
            /*
             * The loop ran out of steps or time. Reported as an ERROR event
             * rather than an empty DONE, because a consumer that paints a
             * finished-looking empty answer has told the user the assistant had
             * nothing to say, which is not what happened.
             */
            yield new \Shirahcan\AiClient\AiStreamEvent(\Shirahcan\AiClient\AiStreamEvent::ERROR, [
                'message' => 'The assistant could not finish within its step budget.',
                'steps'   => $answer->steps,
            ]);

            return;
        }

        if (! $answer->usedAnyTool()) {
            yield from $this->replay($answer);

            return;
        }

        /*
         * Tools ran, so there IS new evidence and the prose is worth writing
         * from it. The transcript the loop built - decisions and observations -
         * becomes the prompt, so the streamed answer is grounded in exactly what
         * was gathered rather than in a summary of it.
         */
        yield from $this->client->generateStream(
            task: $this->task,
            system: $this->systemPrompt($question)
                ."

You have already gathered what you need. Answer the person now, in prose, "
                .'using only the tool results in the conversation. Do not mention the tools by name.',
            prompt: $this->flatten($answer->messages),
        );
    }

    /**
     * Replay a buffered answer as a stream, so one consumer handles both.
     *
     * ⚠ CHUNKED RATHER THAN ONE BIG TOKEN, because a consumer that animates
     * per token would otherwise jump from nothing to everything, and the
     * difference between "it is thinking" and "it is writing" is the whole
     * reason the UI streams at all.
     *
     * @return iterable<\Shirahcan\AiClient\AiStreamEvent>
     */
    private function replay(AssistantAnswer $answer): iterable
    {
        yield new \Shirahcan\AiClient\AiStreamEvent(\Shirahcan\AiClient\AiStreamEvent::OPEN, [
            // Null: there is nothing to cancel, it is already complete.
            'stream_id' => null,
            'replayed'  => true,
        ]);

        if ($answer->provider !== null) {
            yield new \Shirahcan\AiClient\AiStreamEvent(\Shirahcan\AiClient\AiStreamEvent::STAGE, [
                'provider' => $answer->provider,
            ]);
        }

        foreach ($this->chunk($answer->text) as $piece) {
            yield new \Shirahcan\AiClient\AiStreamEvent(\Shirahcan\AiClient\AiStreamEvent::TOKEN, ['text' => $piece]);
        }

        yield new \Shirahcan\AiClient\AiStreamEvent(\Shirahcan\AiClient\AiStreamEvent::DONE, [
            'text' => $answer->text,
        ]);
    }

    /**
     * Split text on word boundaries, so a replay never cuts a word in half.
     *
     * @return array<int, string>
     */
    private function chunk(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $pieces = preg_split('/(?<=\s)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $out = [];
        $buffer = '';

        foreach ($pieces as $piece) {
            $buffer .= $piece;

            if (mb_strlen($buffer) >= 24) {
                $out[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $out[] = $buffer;
        }

        return $out;
    }

    /**
     * The conversation as one prompt, for the streaming endpoint.
     *
     * ⚠ THE STREAM ENDPOINT TAKES A PROMPT, NOT MESSAGES, so a continuing
     * conversation has to be flattened. Roles are labelled rather than dropped:
     * an unlabelled concatenation reads to the model as one person talking to
     * themselves, and it starts answering the wrong turn.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function flatten(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'You' : 'Person';
            $lines[] = $role.': '.(string) ($message['content'] ?? '');
        }

        return implode("

", $lines);
    }

    /**
     * Authorise, run, and describe the outcome to the model.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, int>  $failures
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function dispatch(string $name, array $args, array &$failures): array
    {
        $tool = $this->tools?->find($name);

        if ($tool === null) {
            /*
             * A hallucinated tool name, or one renamed since this conversation
             * started. Told plainly, with the real names, because a model given
             * "unknown tool" and nothing else tends to invent a second one.
             */
            return [
                "There is no tool called {$name}. Available tools: "
                    .implode(', ', $this->tools?->names() ?? []).'.',
                ['name' => $name, 'arguments' => $args, 'outcome' => 'unknown_tool'],
            ];
        }

        /*
         * ⚠ EVERY STEP, WITH THESE ARGUMENTS. A check made once per turn against
         * the first arguments is not a check: the loop can revisit a tool asking
         * for a different case, and that second request is a different question
         * about permission.
         */
        if (! $tool->authorize($this->viewer, $args)) {
            return [
                "You are not permitted to use {$name} for that. Answer without it, "
                    .'and say plainly if that means you cannot answer fully.',
                ['name' => $name, 'arguments' => $args, 'outcome' => 'refused'],
            ];
        }

        try {
            $value = $tool->run($args);
        } catch (Throwable $e) {
            $failures[$name] = ($failures[$name] ?? 0) + 1;

            return [
                "The tool {$name} failed: ".mb_substr($e->getMessage(), 0, 300),
                ['name' => $name, 'arguments' => $args, 'outcome' => 'failed', 'error' => $e->getMessage()],
            ];
        }

        $encoded = $this->encode($value);

        if (mb_strlen($encoded) > $this->maxResultChars) {
            // Visibly, so the model can say the list was cut rather than
            // presenting a truncated set as the whole truth.
            $encoded = mb_substr($encoded, 0, $this->maxResultChars)
                .' ...[truncated; ask for fewer rows or a narrower filter]';
        }

        return [
            "Result of {$name}: {$encoded}",
            ['name' => $name, 'arguments' => $args, 'outcome' => 'ran'],
        ];
    }

    /**
     * The system prompt: the caller's own, plus the tools, plus the knowledge.
     *
     * ⚠ THE PRODUCT'S PROMPT COMES FIRST AND IS NEVER REWRITTEN. The persona -
     * the voice - is added by the SERVICE at its own choke point, from the
     * persona registry, so a product that wanted to change how its assistant
     * sounds edits a row rather than deploying. What is added here is only what
     * the model needs to use this turn's tools.
     */
    private function systemPrompt(string $question): string
    {
        $parts = [$this->system];

        if ($this->tools !== null && ! $this->tools->isEmpty()) {
            $parts[] = "You may use these tools to find facts before answering:\n"
                .$this->encode($this->tools->describe())
                ."\n\nTo use one, answer with action=use_tool and the tool name and arguments. "
                .'To answer the person, use action=answer and put the reply in text. '
                .'Prefer using a tool over guessing, and never invent a tool that is not listed.';
        }

        if ($this->withKnowledge && $question !== '') {
            $grounding = $this->knowledge($question);

            if ($grounding !== null) {
                /*
                 * ⚠ GROUNDING IS OFFERED, NOT ASSERTED. The model is told these
                 * are the product's own notes and that they may not cover the
                 * question, because an instruction to "answer from these" turns
                 * a near-miss retrieval into a confidently wrong answer about
                 * the wrong topic - which is worse than admitting ignorance.
                 */
                $parts[] = "These notes from the product's own documentation may help. "
                    ."They may also be irrelevant; ignore them if so, and never contradict them:\n"
                    .$grounding;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Retrieved knowledge for this question, or null.
     *
     * ⚠ EVERY FAILURE RETURNS NULL. Grounding is an enhancement: an unreachable
     * index must cost context, never the answer.
     */
    private function knowledge(string $question): ?string
    {
        try {
            $found = $this->client->knowledge()->retrieve(
                query: $question,
                audience: $this->knowledgeAudience,
                limit: 4,
            );
        } catch (Throwable) {
            return null;
        }

        $texts = array_values(array_filter(array_map(
            fn (array $m) => trim((string) ($m['text'] ?? '')),
            $found['matches'] ?? [],
        )));

        return $texts === [] ? null : implode("\n\n---\n\n", $texts);
    }

    /** @return array<string, mixed> */
    private function stepSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['answer', 'use_tool']],
                'text'   => ['type' => 'string', 'description' => 'The reply, when action is answer.'],
                'tool'   => [
                    'type' => 'object',
                    'properties' => [
                        'name'      => ['type' => 'string'],
                        'arguments' => ['type' => 'object'],
                    ],
                ],
            ],
            'required' => ['action'],
        ];
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function firstUserText(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'user') {
                return (string) ($message['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $trail
     */
    private function exhaustedAnswer(array $messages, array $trail, int $steps, ?string $provider): AssistantAnswer
    {
        /*
         * ⚠ NO TEXT, AND `exhausted` TRUE. The temptation is to return the last
         * thing the model said; it had not decided to say it, and presenting a
         * mid-thought as an answer is how an assistant states half a fact
         * confidently. The caller decides what to show - usually its
         * deterministic fallback, which is why `exhausted` exists at all.
         */
        return new AssistantAnswer(
            text: '',
            trail: $trail,
            steps: $steps,
            exhausted: true,
            messages: $messages,
            provider: $provider,
        );
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
