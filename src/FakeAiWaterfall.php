<?php

namespace Shirahcan\AiWaterfall;

use Shirahcan\AiWaterfall\Exceptions\AiUnavailableException;
use Throwable;

/**
 * A test double, so every product can exercise its AI paths with no service, no
 * keys and no network.
 *
 * ⚠ THIS IS PART OF THE CONTRACT, NOT A CONVENIENCE. Three products are about to
 * depend on one service; if testing an AI path required that service to be
 * running, each product's suite would become flaky in a way that has nothing to
 * do with the code under test - and the honest response to a flaky suite is to
 * stop running it. Portify's FakeAIProviderAdapter played exactly this role.
 *
 * Queue results (or Throwables) in the order they should be returned:
 *
 *   $fake->queue(['action' => 'ask']);
 *   $fake->queueFailure(new AiUnavailableException('down'));
 */
class FakeAiWaterfall extends AiWaterfallClient
{
    /** @var array<int, mixed> */
    private array $queue = [];

    /** @var array<int, array{task: string, mode: string, payload: mixed}> */
    public array $calls = [];

    public function __construct()
    {
        // Deliberately does NOT call parent::__construct: there is no base URL and
        // no trust key, and a fake that needed either would defeat its own purpose.
    }

    public function queue(mixed $result): static
    {
        $this->queue[] = $result;

        return $this;
    }

    public function queueFailure(Throwable $e): static
    {
        $this->queue[] = $e;

        return $this;
    }

    /**
     * ⚠ RETURNS ITSELF, UNLIKE THE REAL CLIENT, AND THAT IS DELIBERATE.
     *
     * AiWaterfallClient::withBudget() returns a CLONE, which is right for real
     * use - a per-call budget must not leak into the next caller. But a test
     * holds ONE fake and asserts against its recorded $calls, so a clone would
     * silently record the call on an object the test never sees and every
     * assertion would read "no calls" while the code under test worked
     * perfectly. That failure looks like a broken seam and is really a broken
     * double.
     *
     * The budget itself is not modelled here: what a test needs to know is which
     * calls were made, and the real client's own tests cover the wire shape.
     */
    public function withBudget(?float $budgetSeconds, ?int $perProviderTimeout = null): static
    {
        return $this;
    }

    public function generateJson(string $task, string $system, string $prompt, ?int $maxRepairs = null): AiResult
    {
        return $this->next($task, 'json', $prompt);
    }

    public function generateText(string $task, string $system, string $prompt): AiResult
    {
        return $this->next($task, 'text', $prompt);
    }

    public function generateStructured(
        string $task,
        string $system,
        array $messages,
        array $schema = [],
        string $schemaName = 'structured_output',
        array $requiredKeys = [],
        ?int $maxRepairs = null,
    ): AiResult {
        return $this->next($task, 'structured', [
            'system' => $system, 'messages' => $messages,
            'schema' => $schema, 'schema_name' => $schemaName,
            'required_keys' => $requiredKeys,
        ]);
    }

    public function generateFromMedia(
        string $task,
        string $system,
        string $prompt,
        array $media,
        ?int $maxRepairs = null,
    ): AiResult {
        /*
         * ⚠ The MIMES are logged, the BYTES are not. A test double that keeps a
         * client's passport in a public array would put it in every failure dump
         * and every assertion diff the suite ever prints.
         */
        return $this->next($task, 'media', [
            'system' => $system,
            'prompt' => $prompt,
            'media_mimes' => array_column($media, 'mime'),
            'media_count' => count($media),
        ]);
    }

    public function credentialStatus(): array
    {
        return ['credentials' => [], 'benched' => 0];
    }

    public function usage(array $query = []): array
    {
        return ['totals' => ['calls' => count($this->calls)]];
    }

    public function isReachable(): bool
    {
        return true;
    }

    private function next(string $task, string $mode, mixed $payload): AiResult
    {
        $this->calls[] = ['task' => $task, 'mode' => $mode, 'payload' => $payload];

        if ($this->queue === []) {
            /*
             * ⚠ An exhausted queue THROWS rather than returning something empty.
             * A fake that quietly invents a result lets a test pass while asserting
             * nothing, which is worse than no test at all.
             */
            throw new AiUnavailableException('FakeAiWaterfall queue exhausted for task ['.$task.']');
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next instanceof AiResult ? $next : new AiResult(data: $next, provider: 'fake', model: 'fake');
    }
}
