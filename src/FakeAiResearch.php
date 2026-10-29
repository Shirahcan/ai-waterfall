<?php

namespace Shirahcan\AiClient;

/**
 * The research surface of {@see FakeAiClient}.
 *
 * ⚠ IT IS A REAL AiResearch SUBCLASS, NOT A LOOKALIKE. Products type-hint
 * `AiResearch`, so a separate class that merely had the same methods would fail
 * every one of those signatures - and the failure would appear only in the
 * product, long after this package's own tests had gone green.
 */
class FakeAiResearch extends AiResearch
{
    public function __construct(private FakeAiClient $fake)
    {
        parent::__construct($fake);
    }

    public function search(
        string $task,
        string $query,
        int $maxResults = 5,
        array $includeDomains = [],
        string $depth = 'basic',
        bool $includeContent = false,
    ): array {
        return $this->fake->nextSearch($task, $query);
    }

    public function fetch(string $url, ?int $timeout = null, ?int $maxBytes = null): ?array
    {
        return $this->fake->pageFor($url);
    }

    /** The next queued research run (`FakeAiClient::queueResearchRun`); the request is logged. */
    public function run(array $request): array
    {
        return $this->fake->nextResearchRun($request);
    }
}
