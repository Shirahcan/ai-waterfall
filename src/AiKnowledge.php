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
    public function status(): array
    {
        return $this->client->getJson('api/v1/knowledge');
    }
}
