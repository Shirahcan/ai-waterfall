<?php

namespace Shirahcan\AiClient;

/**
 * ai-service's assistant spaces (1.57, Portify plan assistant-spaces-2026-11-08): one person's (or
 * guest's) place with one assistant, in one audience, made on first use. Conversations, memory, input
 * history and the panel's state are read and written through the space, never through a person ref
 * the product passes per call.
 *
 * ⚠ A SPACE ID IS NEVER TAKEN FROM A BROWSER. The product's `SpaceOwner` names the signed-in person,
 * `Laravel\Spaces\Spaces::current()` resolves their space, and only that id is passed in here. The
 * service also refuses any id that is not this product's, but that is the second line, not the first.
 *
 * Answers are ai-service's own JSON shapes (arrays); refusals throw `AiRequestRefusedException` with
 * the service's status, code and sentence, so a route can hand the person the real reason.
 */
class AiSpaces
{
    public function __construct(private AiClient $client) {}

    /** @return array{id: string, assistant: string, owner_kind: string, audience: ?string, created: bool} */
    public function resolve(string $assistant, string $ownerKind, string $ownerRef, ?string $audience, ?string $memoryOwnerRef = null): array
    {
        $body = [
            'assistant'  => $assistant,
            'owner_kind' => $ownerKind,
            'owner_ref'  => $ownerRef,
            'audience'   => $audience,
        ];
        // Where the person's own memory is, when the product files it apart from their threads (1.57.1).
        if ($memoryOwnerRef !== null) {
            $body['memory_owner_ref'] = $memoryOwnerRef;
        }

        return (array) ($this->client->putJson('api/v1/spaces/resolve', $body)['space'] ?? []);
    }

    /**
     * One page of the space's threads, newest activity first.
     *
     * @param  array{limit?: int, pinned?: bool, before?: string}  $query
     * @return array{conversations: list<array<string, mixed>>, has_more: bool, next_before: ?string}
     */
    public function conversations(string $space, array $query = []): array
    {
        if (array_key_exists('pinned', $query)) {
            $query['pinned'] = $query['pinned'] ? 1 : 0;
        }

        return $this->client->getJson($this->path($space, 'conversations'), $query);
    }

    /** @return array{hits: list<array<string, mixed>>, scanned: int, capped: bool} */
    public function search(string $space, string $q, int $limit = 20): array
    {
        return $this->client->getJson($this->path($space, 'conversations/search'), ['q' => $q, 'limit' => $limit]);
    }

    /**
     * @param  array{title?: ?string, subject_ref?: ?string, client_ref?: ?string}  $thread
     * @return array{conversation: array<string, mixed>, created: bool}
     */
    public function start(string $space, array $thread = []): array
    {
        return $this->client->postJson($this->path($space, 'conversations'), $thread);
    }

    /**
     * @param  array{limit?: int, before?: string}  $query
     * @return array{conversation: array<string, mixed>, turns: list<array<string, mixed>>, has_more: bool, next_before: ?string}
     */
    public function conversation(string $space, string $id, array $query = []): array
    {
        return $this->client->getJson($this->path($space, 'conversations/' . rawurlencode($id)), $query);
    }

    /**
     * @param  array{title?: ?string, pinned?: bool, state?: ?array, subject_ref?: ?string}  $changes
     * @return array{conversation: array<string, mixed>}
     */
    public function update(string $space, string $id, array $changes): array
    {
        return $this->client->patchJson($this->path($space, 'conversations/' . rawurlencode($id)), $changes);
    }

    /** @return array{erased: bool, conversations: int, turns: int} */
    public function erase(string $space, string $id): array
    {
        return $this->client->deleteJson($this->path($space, 'conversations/' . rawurlencode($id)));
    }

    /**
     * @param  array<string, mixed>  $turn  role, body/structured, and the optional fields ai-service accepts
     * @return array{turn: array<string, mixed>, conversation: array<string, mixed>, created: bool}
     */
    public function append(string $space, string $id, array $turn): array
    {
        return $this->client->postJson($this->path($space, 'conversations/' . rawurlencode($id) . '/turns'), $turn);
    }

    /** @return array{turn: array<string, mixed>} */
    public function turnState(string $space, string $id, string $turn, ?array $state): array
    {
        return $this->client->patchJson(
            $this->path($space, 'conversations/' . rawurlencode($id) . '/turns/' . rawurlencode($turn)),
            ['state' => $state],
        );
    }

    /**
     * A thumbs up or down on one answer. The voter is the space's owner (the service sets it).
     *
     * @param  array{sentiment: string, reason_code?: ?string, reason_text?: ?string, client_ref?: ?string}  $vote
     * @return array<string, mixed>
     */
    public function vote(string $space, string $id, string $turn, array $vote): array
    {
        return $this->client->postJson(
            $this->path($space, 'conversations/' . rawurlencode($id) . '/turns/' . rawurlencode($turn) . '/feedback'),
            $vote,
        );
    }

    /** @return list<array{key: string, value: mixed, source: string, created_at: ?string, updated_at: ?string}> */
    public function memory(string $space): array
    {
        return array_values((array) ($this->client->getJson($this->path($space, 'memory'))['memory'] ?? []));
    }

    /** @return array{key: string, value: mixed, source: string} */
    public function remember(string $space, string $key, mixed $value, string $source = 'explicit'): array
    {
        return (array) ($this->client->putJson($this->path($space, 'memory/' . rawurlencode($key)), [
            'value'  => $value,
            'source' => $source,
        ])['memory'] ?? []);
    }

    public function forget(string $space, string $key): bool
    {
        return (bool) ($this->client->deleteJson($this->path($space, 'memory/' . rawurlencode($key)))['erased'] ?? false);
    }

    public function forgetAll(string $space): int
    {
        return (int) ($this->client->deleteJson($this->path($space, 'memory'))['memories'] ?? 0);
    }

    /**
     * The person's own console commands (1.62.0, "saved asks"): kept with the space, never in its memory.
     *
     * @return list<array{name: string, ask: string, description: ?string}>
     */
    public function commands(string $space): array
    {
        return array_values((array) ($this->client->getJson($this->path($space, 'commands'))['commands'] ?? []));
    }

    /**
     * Replace them (the console edits them as one list).
     *
     * @param  list<array{name: string, ask: string, description?: ?string}>  $commands
     * @return list<array{name: string, ask: string, description: ?string}>
     */
    public function saveCommands(string $space, array $commands): array
    {
        return array_values((array) ($this->client->putJson($this->path($space, 'commands'), ['commands' => array_values($commands)])['commands'] ?? []));
    }

    /** @return list<string> the person's own questions, newest first, once each */
    public function inputHistory(string $space, int $limit = 50): array
    {
        return array_values(array_map('strval', (array) ($this->client->getJson($this->path($space, 'input-history'), ['limit' => $limit])['history'] ?? [])));
    }

    /** @return array{active_conversation_id: ?string} */
    public function state(string $space): array
    {
        return ['active_conversation_id' => $this->client->getJson($this->path($space, 'state'))['state']['active_conversation_id'] ?? null];
    }

    /** @return array{active_conversation_id: ?string} */
    public function reopenNext(string $space, ?string $conversationId): array
    {
        return ['active_conversation_id' => $this->client->patchJson($this->path($space, 'state'), [
            'active_conversation_id' => $conversationId,
        ])['state']['active_conversation_id'] ?? null];
    }

    /**
     * Suggested questions for the panel, from the product's knowledge for the space's audience, the
     * screen at `$route` first (ai-service picks them; owner 2026-11-08).
     *
     * @return list<string>
     */
    public function prompts(string $space, ?string $route = null, int $limit = 4): array
    {
        $query = ['limit' => $limit];
        if ($route !== null && $route !== '') {
            $query['route'] = $route;
        }

        return array_values(array_map('strval', (array) ($this->client->getJson($this->path($space, 'prompts'), $query)['prompts'] ?? [])));
    }

    /**
     * The memory reference a chat turn names so the assistant's remember/recall use this space's
     * memory (ai-service S1b-ii). Mirrors `AiSpace::memoryRef()` there.
     */
    public static function memoryRef(string $space): string
    {
        return 'space:' . $space;
    }

    private function path(string $space, string $tail): string
    {
        return 'api/v1/spaces/' . rawurlencode($space) . '/' . $tail;
    }
}
