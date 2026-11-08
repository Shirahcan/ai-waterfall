<?php

namespace Shirahcan\AiClient;

use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;

/**
 * An in-memory ai-service spaces store for product tests (1.57). It keeps the rules a product's code
 * can observe: one space per (assistant, kind, ref, audience); a thread or memory key outside the
 * space is a 404 refusal; the list is newest activity first and pages by `before`; erased threads are
 * not reopened. `$calls` records every call for assertions.
 */
class FakeAiSpaces extends AiSpaces
{
    /** @var array<string, array<string, mixed>> */
    public array $spaces = [];

    /** @var array<string, array<string, array<string, mixed>>> space id => thread id => thread */
    public array $threads = [];

    /** @var array<string, list<array<string, mixed>>> thread id => turns */
    public array $turns = [];

    /** @var array<string, array<string, array<string, mixed>>> space id => key => memory */
    public array $memories = [];

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** What `prompts()` answers; a test sets it. */
    public array $suggested = [];

    private int $clock = 0;

    public function __construct()
    {
        // No HTTP client: every method below answers from memory.
    }

    public function resolve(string $assistant, string $ownerKind, string $ownerRef, ?string $audience, ?string $memoryOwnerRef = null): array
    {
        $this->calls[] = ['resolve', func_get_args()];
        $key = implode('|', [$assistant, $ownerKind, $ownerRef, (string) $audience]);
        $created = ! isset($this->spaces[$key]);
        $this->spaces[$key] ??= ['id' => $this->uuid(), 'assistant' => $assistant, 'owner_kind' => $ownerKind,
            'owner_ref' => $ownerRef, 'audience' => $audience, 'state' => ['active_conversation_id' => null]];

        return ['id' => $this->spaces[$key]['id'], 'assistant' => $assistant, 'owner_kind' => $ownerKind,
            'audience' => $audience, 'created' => $created];
    }

    public function conversations(string $space, array $query = []): array
    {
        $this->calls[] = ['conversations', func_get_args()];
        // Chat unless asked (1.58.0): a console session never reaches the panel's list, as in ai-service.
        $channel = $query['channel'] ?? 'chat';
        $rows = array_values(array_filter($this->threads[$space] ?? [], fn ($t) => ($t['channel'] ?? 'chat') === $channel
            && (($query['subject_ref'] ?? '') === '' || ($t['subject_ref'] ?? null) === $query['subject_ref'])));
        if (array_key_exists('pinned', $query)) {
            $rows = array_values(array_filter($rows, fn ($t) => ($t['pinned_at'] !== null) === (bool) $query['pinned']));
        }
        usort($rows, fn ($a, $b) => [$b['_at'], $b['id']] <=> [$a['_at'], $a['id']]);

        if (($query['before'] ?? null) !== null) {
            $at = array_search($query['before'], array_column($rows, 'id'), true);
            if ($at === false) {
                throw new AiRequestRefusedException('That `before` conversation is not in this list.', 422, 'unknown_cursor');
            }
            $rows = array_slice($rows, $at + 1);
        }

        $limit = (int) ($query['limit'] ?? 30);
        $page = array_slice($rows, 0, $limit);

        return [
            'conversations' => array_map(fn ($t) => $this->wire($t), $page),
            'has_more'      => count($rows) > $limit,
            'next_before'   => count($rows) > $limit ? end($page)['id'] : null,
        ];
    }

    public function search(string $space, string $q, int $limit = 20): array
    {
        $this->calls[] = ['search', func_get_args()];
        $hits = [];
        foreach ($this->threads[$space] ?? [] as $thread) {
            if (($thread['channel'] ?? 'chat') !== 'chat') {
                continue;
            }
            $said = array_filter($this->turns[$thread['id']] ?? [], fn ($t) => str_contains(mb_strtolower((string) $t['body']), mb_strtolower($q)));
            if (str_contains(mb_strtolower((string) $thread['title']), mb_strtolower($q))) {
                $hits[] = ['conversation' => $this->wire($thread), 'match' => 'title', 'snippet' => (string) $thread['title'], 'turn_at' => null];
            } elseif ($said !== []) {
                $hits[] = ['conversation' => $this->wire($thread), 'match' => 'turn', 'snippet' => (string) end($said)['body'], 'turn_at' => null];
            }
        }

        return ['hits' => array_slice($hits, 0, $limit), 'scanned' => count($this->threads[$space] ?? []), 'capped' => false];
    }

    public function start(string $space, array $thread = []): array
    {
        $this->calls[] = ['start', func_get_args()];
        $this->spaceOrFail($space);
        $id = $this->uuid();
        $this->threads[$space][$id] = ['id' => $id, 'title' => $thread['title'] ?? null, 'pinned_at' => null,
            'state' => null, 'turn_count' => 0, 'channel' => $thread['channel'] ?? 'chat',
            'subject_ref' => $thread['subject_ref'] ?? null, '_at' => ++$this->clock];

        return ['conversation' => $this->wire($this->threads[$space][$id]), 'created' => true];
    }

    public function conversation(string $space, string $id, array $query = []): array
    {
        $this->calls[] = ['conversation', func_get_args()];
        $thread = $this->threadOrFail($space, $id);

        return ['conversation' => $this->wire($thread), 'turns' => $this->turns[$id] ?? [], 'has_more' => false, 'next_before' => null];
    }

    public function update(string $space, string $id, array $changes): array
    {
        $this->calls[] = ['update', func_get_args()];
        $this->threadOrFail($space, $id);
        if (array_key_exists('title', $changes)) {
            $this->threads[$space][$id]['title'] = $changes['title'];
        }
        if (array_key_exists('pinned', $changes)) {
            $this->threads[$space][$id]['pinned_at'] = $changes['pinned'] ? date(DATE_ATOM) : null;
        }
        if (array_key_exists('state', $changes)) {
            $this->threads[$space][$id]['state'] = $changes['state'];
        }

        return ['conversation' => $this->wire($this->threads[$space][$id])];
    }

    public function erase(string $space, string $id): array
    {
        $this->calls[] = ['erase', func_get_args()];
        $this->threadOrFail($space, $id);
        $turns = count($this->turns[$id] ?? []);
        unset($this->threads[$space][$id], $this->turns[$id]);

        return ['erased' => true, 'conversations' => 1, 'turns' => $turns];
    }

    public function append(string $space, string $id, array $turn): array
    {
        $this->calls[] = ['append', func_get_args()];
        $this->threadOrFail($space, $id);
        $row = ['id' => $this->uuid(), 'role' => $turn['role'] ?? 'user', 'body' => $turn['body'] ?? null,
            'structured' => $turn['structured'] ?? null, 'state' => null, 'created_at' => date(DATE_ATOM)];
        $this->turns[$id][] = $row;
        $this->threads[$space][$id]['turn_count']++;
        $this->threads[$space][$id]['_at'] = ++$this->clock;

        return ['turn' => $row, 'conversation' => $this->wire($this->threads[$space][$id]), 'created' => true];
    }

    public function turnState(string $space, string $id, string $turn, ?array $state): array
    {
        $this->calls[] = ['turnState', func_get_args()];
        $this->threadOrFail($space, $id);
        foreach ($this->turns[$id] ?? [] as $i => $row) {
            if ($row['id'] === $turn) {
                $this->turns[$id][$i]['state'] = $state;

                return ['turn' => $this->turns[$id][$i]];
            }
        }

        throw new AiRequestRefusedException('This conversation holds no turn with that id.', 404, 'no_such_turn');
    }

    public function vote(string $space, string $id, string $turn, array $vote): array
    {
        $this->calls[] = ['vote', func_get_args()];
        $this->threadOrFail($space, $id);

        return ['feedback' => ['sentiment' => $vote['sentiment'] ?? null]];
    }

    public function memory(string $space): array
    {
        $this->calls[] = ['memory', func_get_args()];
        $this->spaceOrFail($space);

        return array_values($this->memories[$space] ?? []);
    }

    public function remember(string $space, string $key, mixed $value, string $source = 'explicit'): array
    {
        $this->calls[] = ['remember', func_get_args()];
        $this->spaceOrFail($space);

        return $this->memories[$space][$key] = ['key' => $key, 'value' => $value, 'source' => $source, 'created_at' => null, 'updated_at' => null];
    }

    public function forget(string $space, string $key): bool
    {
        $this->calls[] = ['forget', func_get_args()];
        $had = isset($this->memories[$space][$key]);
        unset($this->memories[$space][$key]);

        return $had;
    }

    public function forgetAll(string $space): int
    {
        $this->calls[] = ['forgetAll', func_get_args()];
        $n = count($this->memories[$space] ?? []);
        $this->memories[$space] = [];

        return $n;
    }

    public function inputHistory(string $space, int $limit = 50): array
    {
        $this->calls[] = ['inputHistory', func_get_args()];
        $asked = [];
        foreach ($this->threads[$space] ?? [] as $thread) {
            if (($thread['channel'] ?? 'chat') !== 'chat') {
                continue;
            }
            foreach ($this->turns[$thread['id']] ?? [] as $turn) {
                if ($turn['role'] === 'user' && trim((string) $turn['body']) !== '') {
                    $asked[] = trim((string) $turn['body']);
                }
            }
        }

        return array_slice(array_values(array_unique(array_reverse($asked))), 0, $limit);
    }

    public function prompts(string $space, ?string $route = null, int $limit = 4): array
    {
        $this->calls[] = ['prompts', func_get_args()];
        $this->spaceOrFail($space);

        return array_slice(array_values($this->suggested), 0, $limit);
    }

    public function state(string $space): array
    {
        $this->calls[] = ['state', func_get_args()];
        $active = $this->spaceOrFail($space)['state']['active_conversation_id'] ?? null;

        return ['active_conversation_id' => $active !== null && isset($this->threads[$space][$active]) ? $active : null];
    }

    public function reopenNext(string $space, ?string $conversationId): array
    {
        $this->calls[] = ['reopenNext', func_get_args()];
        if ($conversationId !== null) {
            $this->threadOrFail($space, $conversationId);
        }
        foreach ($this->spaces as $key => $row) {
            if ($row['id'] === $space) {
                $this->spaces[$key]['state']['active_conversation_id'] = $conversationId;
            }
        }

        return ['active_conversation_id' => $conversationId];
    }

    /** @return array<string, mixed> */
    private function spaceOrFail(string $space): array
    {
        foreach ($this->spaces as $row) {
            if ($row['id'] === $space) {
                return $row;
            }
        }

        throw new AiRequestRefusedException('This product holds no space with that id.', 404, 'no_such_space');
    }

    /** @return array<string, mixed> */
    private function threadOrFail(string $space, string $id): array
    {
        return $this->threads[$space][$id]
            ?? throw new AiRequestRefusedException('This space holds no conversation with that id.', 404, 'no_such_conversation');
    }

    /** @param array<string, mixed> $thread @return array<string, mixed> */
    private function wire(array $thread): array
    {
        unset($thread['_at']);

        return $thread + ['last_turn_at' => null, 'created_at' => null];
    }

    private function uuid(): string
    {
        return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
    }
}
