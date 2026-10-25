<?php

namespace Shirahcan\AiClient;

/**
 * A product's knowledge SERVING INDEX, in ai-service.
 *
 * ⚠ THE AUTHORITY STAYS IN YOUR PRODUCT, AND THAT SPLIT IS THE DESIGN. What is
 * TRUE - and whether an entry still names a route or a tool that exists - lives
 * with you, where your own tests can fail on it. A corpus whose staleness
 * nobody can test is how a stale government fee becomes a confident lie; one
 * cost $1,000 a position. What lives here is the INDEX built from that corpus,
 * because an index is built from an embedding model and the service owns the
 * model.
 *
 * ⚠ SO DO NOT TREAT THIS AS STORAGE. If the only copy of an entry is in the
 * service, nothing can test it any more.
 *
 * The shape of a sync:
 *
 *     foreach ($entries->chunk(50) as $batch) {
 *         $ai->knowledge()->sync($batch->map(fn ($e) => [
 *             'slug'     => $e->slug,
 *             'text'     => $e->embeddableText(),
 *             'audience' => $e->audience,     // null = every audience
 *         ])->all());
 *     }
 *
 *     $ai->knowledge()->retire($everySlugYouStillPublish);   // once, at the end
 */
class AiKnowledge
{
    /** The service's own cap on one sync batch. */
    public const MAX_BATCH = 50;

    public function __construct(private AiClient $client) {}

    /**
     * Push a batch of entries. Only changed text is re-embedded.
     *
     * ⚠ THE SERVICE SKIPS BY CHECKSUM, SO SYNC OFTEN AND CHEAPLY. Re-sending an
     * unchanged corpus costs one request and no provider calls. What it will
     * NOT skip is text whose embedding model has changed, because a vector
     * built by another model cannot be scored against today's queries.
     *
     * ⚠ BATCHES ARE CAPPED AT 50 and the service refuses more. An embedding
     * call per changed entry means an unbounded batch is an unbounded request:
     * a 500-entry first sync would run for minutes and take a gateway timeout
     * with half the work done and nothing saying which half.
     *
     * @param  array<int, array{slug: string, text: string, audience?: ?string, ord?: int}>  $entries
     * @return array{synced: int, embedded: int, skipped: int, failed: array<string, string>, model: string}
     */
    public function sync(array $entries, string $task = 'knowledge.embed.document'): array
    {
        return $this->client->postJson('api/v1/knowledge/sync', [
            'entries' => array_values($entries),
            'task'    => $task,
        ]);
    }

    /**
     * Delete every chunk whose slug is not in the list.
     *
     * ⚠ PASS EVERY SLUG YOU STILL PUBLISH, not the ones you removed. The list
     * is the whole truth about what should exist, which is the only shape that
     * cannot drift: a "delete these" call silently keeps anything a crashed
     * sync forgot to mention.
     *
     * ⚠ AN EMPTY LIST IS REFUSED unless `$confirmEmpty` is true. "This product
     * publishes nothing" and "the sync crashed before it collected the slugs"
     * are the same request on the wire, and one of them would wipe a working
     * index - leaving an assistant that answers nothing, from a call that
     * returned 200.
     *
     * @param  array<int, string>  $keepSlugs
     * @return int how many chunks were removed
     */
    public function retire(array $keepSlugs, bool $confirmEmpty = false): int
    {
        $payload = ['keep' => array_values(array_unique($keepSlugs))];

        if ($confirmEmpty) {
            $payload['confirm_empty'] = true;
        }

        return (int) ($this->client->postJson('api/v1/knowledge/retire', $payload)['retired'] ?? 0);
    }

    /**
     * The best chunks for a question.
     *
     * ⚠ `$audience` IS A HARD FILTER, NOT A RANKING HINT, and null means "the
     * audience-blind entries ONLY" rather than everything. Pass the asking
     * user's audience. The recurring defect it exists for: a practising
     * consultant asked how to set up instalments for a client and was told to
     * "ask your consultant" - she IS the consultant - because one entry served
     * every audience.
     *
     * ⚠ READ `index_stale`. True means every chunk was built by a different
     * embedding model, so nothing could be scored: the answer is to re-sync,
     * not to conclude the question had no match. An empty `matches` on its own
     * cannot tell those apart.
     *
     * ⚠ AND IT THROWS WHEN THE QUERY CANNOT BE EMBEDDED, deliberately, because
     * semantic retrieval is an enhancement: catch it and fall back to your own
     * lexical path rather than telling the user there is no answer.
     *
     * @return array{
     *     matches: array<int, array{slug: string, audience: ?string, ord: int, score: float, text: string}>,
     *     model: string, scored: int, skipped_stale_model: int, index_stale: bool
     * }
     */
    public function retrieve(
        string $query,
        ?string $audience = null,
        int $limit = 5,
        ?float $floor = null,
        string $task = 'knowledge.embed.query',
        ?float $budgetSeconds = null,
    ): array {
        $body = ['query' => $query, 'limit' => $limit, 'task' => $task];

        if ($audience !== null) {
            $body['audience'] = $audience;
        }

        if ($floor !== null) {
            $body['floor'] = $floor;
        }

        if ($budgetSeconds !== null) {
            $body['budget_seconds'] = $budgetSeconds;
        }

        return $this->client->postJson('api/v1/knowledge/retrieve', $body);
    }

    /**
     * What this product's index looks like right now.
     *
     * ⚠ `stale_chunks` is the number an operator acts on: chunks that exist,
     * carry a vector, and cannot be scored because a different model built
     * them. A non-zero value means a re-sync is owed, and until it happens
     * semantic retrieval is quietly doing nothing.
     *
     * @return array<string, mixed>
     */
    /*
    |--------------------------------------------------------------------------
    | P21f: knowledge ENTRIES, stored and searched in the service
    |--------------------------------------------------------------------------
    */

    /**
     * Publish entries with their serving facets. Batches of 50, as the service caps them.
     *
     * @param  list<array{slug: string, question: string, answer: string, status?: string, audiences?: ?list<string>, expires_at?: ?string, aliases?: list<string>, description?: ?string, extra?: array<string, mixed>}>  $entries
     * @return array{synced: int, unchanged: int}
     */
    public function syncEntries(array $entries, string $task = 'knowledge.embed.document'): array
    {
        $totals = ['synced' => 0, 'unchanged' => 0];

        foreach (array_chunk(array_values($entries), 50) as $batch) {
            $result = $this->client->postJson('api/v1/knowledge/entries/sync', ['entries' => $batch, 'task' => $task]);
            $totals['synced'] += (int) ($result['synced'] ?? 0);
            $totals['unchanged'] += (int) ($result['unchanged'] ?? 0);
        }

        return $totals;
    }

    /** Remove every entry not in $keepSlugs. An empty list is refused unless confirmed, as for retire(). */
    public function retireEntries(array $keepSlugs, bool $confirmEmpty = false): int
    {
        return (int) ($this->client->postJson('api/v1/knowledge/entries/retire', [
            'keep'          => array_values($keepSlugs),
            'confirm_empty' => $confirmEmpty,
        ])['retired'] ?? 0);
    }

    /**
     * The best SERVABLE entries for a question (published, unexpired, this audience).
     *
     * @return list<array{entry: array<string, mixed>, score: float, lexical: float, semantic: float}>
     */
    public function search(string $query, ?string $audience, int $limit = 5): array
    {
        return array_values((array) ($this->client->postJson('api/v1/knowledge/entries/search', array_filter([
            'query'    => $query,
            'audience' => $audience,
            'limit'    => $limit,
        ], fn ($v) => $v !== null && $v !== ''))['matches'] ?? []));
    }

    /**
     * Browse entries. Servable only by default; `$all` includes pulled and expired ones (for review).
     *
     * @return array{entries: list<array<string, mixed>>, meta: array{page: int, last_page: int, total: int}}
     */
    public function entries(?string $audience = null, bool $all = false, int $page = 1, int $perPage = 50): array
    {
        $payload = $this->client->getJson('api/v1/knowledge/entries', array_filter([
            'audience' => $audience, 'all' => $all ? 1 : null, 'page' => $page, 'per_page' => $perPage,
        ], fn ($v) => $v !== null && $v !== ''));

        return ['entries' => array_values((array) ($payload['entries'] ?? [])), 'meta' => (array) ($payload['meta'] ?? [])];
    }

    /** @return array<string, mixed>|null one entry by slug, servable or not */
    public function entry(string $slug): ?array
    {
        try {
            return (array) ($this->client->getJson('api/v1/knowledge/entries/' . rawurlencode($slug))['entry'] ?? []) ?: null;
        } catch (Exceptions\AiRequestRefusedException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function status(): array
    {
        return $this->client->getJson('api/v1/knowledge');
    }
}
