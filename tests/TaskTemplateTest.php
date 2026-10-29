<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Tasks\TaskTemplateFile;

/** A product's task as a template file, synced, then run with variables only (1.30). */
class TaskTemplateTest extends TestCase
{
    private const FILE = <<<'MD'
    ---
    key: intake.question.employer
    task: intake.question
    mode: json          # forced JSON
    sensitive: true
    required_keys: [question, why]
    ---
    ## System
    You write one intake question.

    ## Prompt
    Program: {{program}}.

    ## Schema
    ```json
    {"type": "object", "properties": {"question": {"type": "string"}}}
    ```
    MD;

    public function test_a_template_file_parses_into_the_sync_payload(): void
    {
        $task = TaskTemplateFile::parse(self::FILE);

        $this->assertSame('intake.question.employer', $task['key']);
        $this->assertSame('intake.question', $task['task']);
        $this->assertSame('json', $task['mode']);
        $this->assertTrue($task['sensitive']);
        $this->assertSame(['question', 'why'], $task['required_keys']);
        $this->assertSame('You write one intake question.', $task['system']);
        $this->assertSame('Program: {{program}}.', $task['prompt']);
        $this->assertSame('object', $task['json_schema']['type']);
    }

    public function test_a_malformed_template_is_refused_with_its_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt');

        TaskTemplateFile::parse("---\nkey: a.b\nmode: text\n---\n## System\nx\n");
    }

    public function test_run_task_posts_variables_only_to_the_tasks_route(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], (string) json_encode(['result' => ['question' => 'Q?'], 'provider' => 'gemini']))]));
        $stack->push(Middleware::history($sent));
        $client = new AiClient('http://127.0.0.1:8007', 'k', 60, new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));

        $result = $client->withCorrelation(['_key' => 'case:1'])->runTask('intake.question', ['program' => 'PGWP']);

        $this->assertSame('Q?', $result->array()['question']);
        $request = $sent[0]['request'];
        $this->assertSame('/api/v1/tasks/intake.question/run', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['program' => 'PGWP'], $body['vars']);
        $this->assertArrayNotHasKey('system', $body, 'a product never sends a prompt');
        $this->assertSame('case:1', $body['correlation']['_key']);
    }

    public function test_the_fake_runs_tasks_from_its_queue_and_logs_the_variables(): void
    {
        $fake = (new FakeAiClient())->queue(['question' => 'Q?']);

        $this->assertSame('Q?', $fake->runTask('intake.question', ['program' => 'x'])->array()['question']);
        $this->assertSame(['task' => 'intake.question', 'mode' => 'task', 'payload' => ['vars' => ['program' => 'x'], 'media' => 0]], $fake->log->calls[0]);
    }
}
