<?php

namespace Shirahcan\AiClient\Laravel\Conversations\Threads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Laravel\Conversations\HeldConversations;
use Throwable;

/**
 * The conversation list that scales, for every product (1.53.0, moved out of Portify's
 * AssistantThreadsController): paged, pinned, searched and renamed. Answers in the estate's API shape
 * (`{success, message, data}`), which ai-ui's `useAssistantThreads` reads.
 *
 * ⚠ OWN THREADS ONLY, ALWAYS. Every read and write starts from the product's rows for the signed-in person, and a
 * service id that comes back from a search or the pinned list is mapped THROUGH those rows: an id that is not
 * theirs has no row and is dropped. The service's conversation ids never reach the browser.
 */
final class ThreadsController extends Controller
{
    private const PAGE_DEFAULT = 30;

    private const PAGE_MAX = 50;

    private const TITLE_MAX = 120;

    /** ai-service refuses an 11th pin, so the pinned list never needs more than this. */
    private const PINS_MAX = 10;

    public function __construct(private readonly AiClient $ai, private readonly ThreadIndex $index) {}

    /** One page of the person's threads, newest first, and on the first page their pinned ones. */
    public function index(Request $request): JsonResponse
    {
        if (! $this->index->enabled()) {
            return $this->fail('Not found', 404);
        }

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::PAGE_MAX],
            'before' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $request->user();
        $before = $data['before'] ?? null;
        $page = $this->index->page($user, $before, (int) ($data['limit'] ?? self::PAGE_DEFAULT));
        $pins = $this->pinnedServiceIds($user);
        $pinnedIds = array_flip($pins);

        $pinnedRows = [];
        if ($before === null || $before === '') {
            $byService = $this->index->byServiceIds($user, $pins);
            foreach ($pins as $serviceId) {
                if (isset($byService[$serviceId])) {
                    $pinnedRows[] = $byService[$serviceId]->toArray(true);
                }
            }
        }

        return $this->ok([
            'items' => array_map(fn (ThreadRow $r) => $r->toArray($r->serviceId !== null && isset($pinnedIds[$r->serviceId])), $page['rows']),
            'next_before' => $page['next'],
            'pinned' => $pinnedRows,
        ]);
    }

    /** The person's threads that mention some words, newest first, with where they were found. */
    public function search(Request $request): JsonResponse
    {
        if (! $this->index->enabled()) {
            return $this->fail('Not found', 404);
        }

        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $user = $request->user();

        try {
            $found = $this->ai->conversations()->search($this->index->ownerRef($user), trim((string) $data['q']), 20);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Search is not available right now. Try again in a moment.', 503);
        }

        $mine = $this->index->byServiceIds($user, array_map(fn (array $hit) => $hit['conversation']->id, $found['hits']));
        $results = [];
        foreach ($found['hits'] as $hit) {
            $row = $mine[$hit['conversation']->id] ?? null;
            if ($row === null) {
                continue; // not this person's thread in this product: never shown
            }
            $results[] = $row->toArray($hit['conversation']->pinnedAt !== null) + ['snippet' => $hit['snippet'], 'match' => $hit['match']];
        }

        return $this->ok(['results' => $results, 'capped' => $found['capped']]);
    }

    /** Rename and/or pin one of the person's own threads. */
    public function update(Request $request, string $id): JsonResponse
    {
        if (! $this->index->enabled()) {
            return $this->fail('Not found', 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:'.self::TITLE_MAX],
            'pinned' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) {
            return $this->fail('Say what to change: a new name, or pin or unpin.', 422);
        }

        $user = $request->user();
        $row = $this->index->find($user, $id);
        if ($row === null) {
            return $this->fail('Conversation not found', 404);
        }

        $pinned = null;
        try {
            if (array_key_exists('title', $data)) {
                $row = $this->index->rename($user, $id, trim((string) $data['title'])) ?? $row;
            }

            if (array_key_exists('pinned', $data)) {
                if ($row->serviceId === null) {
                    return $this->fail('This conversation cannot be pinned yet. Ask something in it first.', 422);
                }
                $held = $this->ai->conversations()->update($row->serviceId, ['pinned' => (bool) $data['pinned']]);
                // A product whose model holds its title in ai-service reads this answer instead of asking again.
                app(HeldConversations::class)->put($held);
                $pinned = $held->pinnedAt !== null;
            }
        } catch (AiRequestRefusedException $e) {
            // The service's own words, written for the person ("You can pin up to 10 conversations.").
            return $this->fail($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('That change did not save. Try again in a moment.', 503);
        }

        $pinned ??= $row->serviceId !== null && in_array($row->serviceId, $this->pinnedServiceIds($user), true);

        return $this->ok(['conversation' => $row->toArray($pinned)]);
    }

    /** @return list<string> the person's pinned ai-service ids, in the order they pinned them */
    private function pinnedServiceIds(\Illuminate\Contracts\Auth\Authenticatable $user): array
    {
        try {
            $held = $this->ai->conversations()->forOwner($this->index->ownerRef($user), null, self::PINS_MAX, true);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        usort($held, fn ($a, $b) => ($a->pinnedAt?->getTimestamp() ?? 0) <=> ($b->pinnedAt?->getTimestamp() ?? 0));

        return array_values(array_map(fn ($c) => (string) $c->id, $held));
    }

    /** @param array<string, mixed> $data */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Success', 'data' => $data]);
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
