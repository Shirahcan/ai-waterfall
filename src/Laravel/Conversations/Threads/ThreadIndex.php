<?php

namespace Shirahcan\AiClient\Laravel\Conversations\Threads;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A product's own conversation rows, as the kit's thread routes read and write them (1.53.0).
 *
 * The product keeps a row per conversation (id, title, when, and the ai-service conversation it points at);
 * ai-service holds the turns, the pins and the search. This contract is the bridge, so `ThreadRoutes` can page,
 * search, rename and pin for every product with one controller. Most products extend
 * {@see EloquentThreadIndex} and name their model.
 *
 * ⚠ OWN ROWS ONLY. Every method takes the signed-in person and must never return another person's row: an id
 * that is not theirs reads as absent, so its existence is not disclosed either.
 */
interface ThreadIndex
{
    /** The product's switch for the whole list (Porter's and Mira's `ai.chat_time_and_threads.enabled`). Off = 404. */
    public function enabled(): bool;

    /** Who owns the person's conversations in ai-service: what the product passes as `owner_ref` when it starts one. */
    public function ownerRef(Authenticatable $user): string;

    /**
     * One page of the person's rows, newest first, after the cursor.
     *
     * @return array{rows: list<ThreadRow>, next: ?string}
     */
    public function page(Authenticatable $user, ?string $before, int $limit): array;

    /**
     * The person's rows that point at these ai-service conversations.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, ThreadRow> keyed by service id
     */
    public function byServiceIds(Authenticatable $user, array $serviceIds): array;

    public function find(Authenticatable $user, string $id): ?ThreadRow;

    /** Rename one of the person's rows; null when it is not theirs. */
    public function rename(Authenticatable $user, string $id, string $title): ?ThreadRow;
}
