<?php

namespace Shirahcan\AiClient;

/**
 * What an assistant remembers about a person, held by ai-service (P19e).
 *
 * ⚠ BEFORE THIS, MEMORY WAS ONE PRODUCT'S TABLE. Portify's `porter_user_memory` held it and no
 * other assistant could remember anything without a second copy. The service now holds it,
 * encrypted, scoped to the product's trust key, and erased with the person.
 *
 * ⚠ `ownerRef` IS OPAQUE TO THE SERVICE, exactly as it is for conversations. The product decides
 * whose memory it may read; the service only guarantees that one product never reads another's.
 */
class AiMemoryStore
{
    public function __construct(private AiClient $client) {}

    /**
     * Everything remembered about one person.
     *
     * @return array<string, array{value: mixed, source: string, updated_at: ?string}> keyed by memory key
     */
    public function all(string $ownerRef): array
    {
        $rows = (array) ($this->client->getJson('api/v1/memory', ['owner_ref' => $ownerRef])['memory'] ?? []);
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['key']] = [
                'value'      => $row['value'] ?? null,
                'source'     => (string) ($row['source'] ?? 'explicit'),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Remember one thing, or correct it. Calling it again for the same key replaces the value.
     *
     * @param  'explicit'|'inferred'  $source  whether the person said it or the assistant concluded it
     */
    public function put(string $ownerRef, string $key, mixed $value, string $source = 'explicit'): void
    {
        $this->client->putJson('api/v1/memory/' . rawurlencode($key), [
            'owner_ref' => $ownerRef,
            'value'     => $value,
            'source'    => $source,
        ]);
    }

    public function forget(string $ownerRef, string $key): void
    {
        $this->client->deleteJson('api/v1/memory/' . rawurlencode($key), ['owner_ref' => $ownerRef]);
    }

    /** Forget everything about one person. An erasure: it refuses to run without one. */
    public function clear(string $ownerRef): int
    {
        if (trim($ownerRef) === '') {
            throw new \InvalidArgumentException('Clearing memory needs an owner; "forget everybody" is not an operation.');
        }

        return (int) ($this->client->deleteJson('api/v1/memory', ['owner_ref' => $ownerRef])['memories'] ?? 0);
    }
}
