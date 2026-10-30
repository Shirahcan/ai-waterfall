<?php

namespace Shirahcan\AiClient\Laravel\Memory;

use Shirahcan\AiClient\AiClient;

/**
 * What an assistant remembers about a person, held by ai-service and read once per request.
 *
 * Generalised from Portify's `ServiceHeldMemory`. Bind it as a scoped singleton so one request
 * that assembles a prompt and then renders a memory panel reads the service once.
 *
 * ⚠ A READ THAT FAILS IS EMPTY, REPORTED, AND NOT REMEMBERED. Memory is context, not a contract:
 * an assistant that cannot recall a preference should still answer. Not caching the empty result
 * means the next read in the same request can still succeed, and {@see lastReadFailed()} lets a
 * memory SCREEN say "could not load" instead of the lie "you have no memories".
 *
 * ⚠ WRITES ARE NOT SWALLOWED. A person who asked to be remembered or forgotten must hear that it
 * did not happen, so put()/forget() throw and the caller surfaces it.
 */
final class HeldMemory
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $held = [];

    private bool $readFailed = false;

    public function __construct(private readonly AiClient $ai) {}

    /** @return array<string, array<string, mixed>> key => {value, source, ...} */
    public function all(string $ownerRef): array
    {
        if (array_key_exists($ownerRef, $this->held)) {
            return $this->held[$ownerRef];
        }

        try {
            $this->readFailed = false;

            return $this->held[$ownerRef] = $this->ai->memory()->all($ownerRef);
        } catch (\Throwable $e) {
            report($e);
            $this->readFailed = true;

            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public function find(string $ownerRef, string $key): ?array
    {
        return $this->all($ownerRef)[$key] ?? null;
    }

    public function put(string $ownerRef, string $key, mixed $value, string $source = 'explicit'): void
    {
        $this->ai->memory()->put($ownerRef, $key, $value, $source);
        unset($this->held[$ownerRef]);
    }

    public function forget(string $ownerRef, string $key): void
    {
        $this->ai->memory()->forget($ownerRef, $key);
        unset($this->held[$ownerRef]);
    }

    /** Drop this request's copy (after a write somewhere else). */
    public function release(string $ownerRef): void
    {
        unset($this->held[$ownerRef]);
    }

    public function lastReadFailed(): bool
    {
        return $this->readFailed;
    }
}
