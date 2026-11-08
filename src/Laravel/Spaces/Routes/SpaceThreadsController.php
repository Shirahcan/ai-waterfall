<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Routes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shirahcan\AiClient\Laravel\Spaces\SpaceMessages;

/**
 * The person's conversations in their space (1.57): the list that scales (paged, pins first, search,
 * rename, pin), reading one thread, and erasing one. The same response shapes ai-ui's
 * `useAssistantThreads` already reads from `ThreadRoutes`, so the hook needs no change.
 */
final class SpaceThreadsController extends SpaceController
{
    private const PAGE_DEFAULT = 30;

    private const PAGE_MAX = 50;

    private const TITLE_MAX = 120;

    /** ai-service refuses an 11th pin, so the pinned list never needs more. */
    private const PINS_MAX = 10;

    /** One page, newest activity first; on the first page, the pinned ones too. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::PAGE_MAX],
            'before' => ['nullable', 'uuid'],
        ]);

        return $this->inSpace($request, function (string $space) use ($data) {
            $query = ['limit' => (int) ($data['limit'] ?? self::PAGE_DEFAULT)];
            if (($data['before'] ?? null) !== null) {
                $query['before'] = $data['before'];
            }
            $page = $this->ai->spaces()->conversations($space, $query);

            $pinned = [];
            if (($data['before'] ?? null) === null) {
                $pins = $this->ai->spaces()->conversations($space, ['pinned' => true, 'limit' => self::PINS_MAX])['conversations'] ?? [];
                // In the order they were pinned, as the shared list draws them.
                usort($pins, fn ($a, $b) => strcmp((string) ($a['pinned_at'] ?? ''), (string) ($b['pinned_at'] ?? '')));
                $pinned = array_map(fn (array $c) => $this->row($c), $pins);
            }

            return [
                'items' => array_map(fn (array $c) => $this->row($c), $page['conversations'] ?? []),
                'next_before' => ($page['has_more'] ?? false) ? ($page['next_before'] ?? null) : null,
                'pinned' => $pinned,
            ];
        });
    }

    /** The person's threads that mention some words, newest first, with where they were found. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return $this->inSpace($request, function (string $space) use ($data) {
            $found = $this->ai->spaces()->search($space, trim((string) $data['q']), 20);

            return [
                'results' => array_map(
                    fn (array $hit) => $this->row($hit['conversation']) + ['snippet' => $hit['snippet'] ?? '', 'match' => $hit['match'] ?? 'turn'],
                    $found['hits'] ?? [],
                ),
                'capped' => (bool) ($found['capped'] ?? false),
            ];
        });
    }

    /** Rename and/or pin one of the person's own threads. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:'.self::TITLE_MAX],
            'pinned' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) {
            return $this->fail('Say what to change: a new name, or pin or unpin.', 422);
        }

        return $this->inSpace($request, function (string $space) use ($id, $data) {
            $changes = [];
            if (array_key_exists('title', $data)) {
                $changes['title'] = trim((string) $data['title']);
            }
            if (array_key_exists('pinned', $data)) {
                $changes['pinned'] = (bool) $data['pinned'];
            }

            return ['conversation' => $this->row($this->ai->spaces()->update($space, $id, $changes)['conversation'])];
        });
    }

    /**
     * One thread with a page of its turns, oldest first within the page. `before` pages back.
     *
     * Turns are returned as ai-service holds them (`structured` carries a product's cards and actions);
     * ai-ui's space client turns them into panel messages, so every product reads them the same way.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'before' => ['nullable', 'uuid'],
        ]);

        return $this->inSpace($request, function (string $space) use ($request, $id, $data) {
            $read = $this->ai->spaces()->conversation($space, $id, array_filter($data, fn ($v) => $v !== null));

            return [
                'conversation' => $this->row($read['conversation']),
                // The product's own message shape when it binds SpaceMessages (Porter does); neutral otherwise.
                'messages' => app(SpaceMessages::class)->present($request, $read['conversation'], $read['turns'] ?? []),
                'has_more' => (bool) ($read['has_more'] ?? false),
                'next_before' => $read['next_before'] ?? null,
            ];
        });
    }

    /** Erase one of the person's threads and everything said in it. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->inSpace($request, function (string $space) use ($id) {
            $receipt = $this->ai->spaces()->erase($space, $id);

            return ['erased' => (bool) ($receipt['erased'] ?? false), 'turns' => (int) ($receipt['turns'] ?? 0)];
        });
    }
}
