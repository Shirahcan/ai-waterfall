<?php

namespace Shirahcan\AiClient;

/**
 * The memory store with no network, behaving as the service does (P19e).
 *
 * ⚠ IT REFUSES WHAT THE SERVICE REFUSES: an empty or over-long key, and clearing with no owner.
 */
class FakeAiMemoryStore extends AiMemoryStore
{
    /** @var array<string, array<string, array{value: mixed, source: string, updated_at: ?string}>> owner => key => row */
    public array $rows = [];

    public function __construct()
    {
        // Deliberately no parent::__construct: there is no client and no network.
    }

    public function all(string $ownerRef): array
    {
        return $this->rows[$ownerRef] ?? [];
    }

    public function put(string $ownerRef, string $key, mixed $value, string $source = 'explicit'): void
    {
        if (trim($key) === '' || mb_strlen($key) > 64) {
            throw new \RuntimeException('ai-service answered 422: A memory key is 1-64 characters.');
        }

        if (! in_array($source, ['explicit', 'inferred'], true)) {
            throw new \RuntimeException("ai-service answered 422: unknown memory source {$source}.");
        }

        $this->rows[$ownerRef][$key] = ['value' => $value, 'source' => $source, 'updated_at' => gmdate('c')];
    }

    public function forget(string $ownerRef, string $key): void
    {
        unset($this->rows[$ownerRef][$key]);
    }

    public function clear(string $ownerRef): int
    {
        if (trim($ownerRef) === '') {
            throw new \InvalidArgumentException('Clearing memory needs an owner; "forget everybody" is not an operation.');
        }

        $count = count($this->rows[$ownerRef] ?? []);
        unset($this->rows[$ownerRef]);

        return $count;
    }
}
