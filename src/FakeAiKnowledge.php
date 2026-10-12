<?php

namespace Shirahcan\AiClient;

/**
 * An in-memory knowledge index, so a product can test its sync and its
 * retrieval with no service and no embedding spend.
 *
 * ⚠ IT MODELS THE RULES, NOT JUST THE SHAPE, because the rules are what a
 * consumer gets wrong:
 *
 *   - the audience is a HARD FILTER, and null means audience-blind only;
 *   - an unchanged entry is SKIPPED rather than re-embedded;
 *   - `retire()` refuses an empty list unless it is confirmed;
 *   - a model change makes the index STALE rather than merely empty.
 *
 * ⚠ ITS SCORING IS DELIBERATELY NOT SEMANTIC. It ranks by token overlap, which
 * no test should assert the exact numbers of - what a product's tests need to
 * know is which SLUG came back and that the filters held. A fake that pretended
 * to do cosine similarity would invite assertions on scores that the real
 * model would never reproduce, and those tests would be noise.
 */
class FakeAiKnowledge extends AiKnowledge
{
    /** @var array<string, array{slug: string, text: string, audience: ?string, ord: int, checksum: string, model: ?string}> */
    private array $chunks = [];

    /** @var array<int, array<string, mixed>> */
    public array $syncs = [];

    /** @var array<int, array{query: string, audience: ?string}> */
    public array $retrievals = [];

    private string $model = 'fake-embedding-001';

    public function __construct()
    {
        // Deliberately no parent::__construct: there is no client and no network.
    }

    /**
     * Pretend the service switched embedding model, without re-syncing.
     *
     * This is how a product exercises its "the index is stale, re-sync" branch,
     * which is the one nobody writes by accident and the one that silently
     * disables semantic retrieval when it is missing.
     */
    public function changeModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function sync(array $entries, string $task = 'knowledge.embed.document'): array
    {
        $synced = 0;
        $embedded = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $slug = (string) $entry['slug'];
            $audience = ($entry['audience'] ?? null) === '' ? null : ($entry['audience'] ?? null);
            $ord = (int) ($entry['ord'] ?? 0);
            $text = (string) $entry['text'];
            $checksum = hash('sha256', $text);
            $key = $slug.'|'.($audience ?? '').'|'.$ord;

            $existing = $this->chunks[$key] ?? null;

            // ⚠ BOTH CONDITIONS, as the service does: unchanged text embedded by
            // a model we no longer use must still be re-embedded.
            if ($existing !== null && $existing['checksum'] === $checksum && $existing['model'] === $this->model) {
                $skipped++;

                continue;
            }

            $this->chunks[$key] = [
                'slug' => $slug, 'text' => $text, 'audience' => $audience,
                'ord' => $ord, 'checksum' => $checksum, 'model' => $this->model,
            ];

            $synced++;
            $embedded++;
        }

        $result = [
            'synced' => $synced, 'embedded' => $embedded, 'skipped' => $skipped,
            'failed' => [], 'model' => $this->model,
        ];

        $this->syncs[] = $result + ['count' => count($entries)];

        return $result;
    }

    public function retire(array $keepSlugs, bool $confirmEmpty = false): int
    {
        $keep = array_values(array_unique($keepSlugs));

        if ($keep === [] && ! $confirmEmpty) {
            /*
             * ⚠ THE FAKE REFUSES TOO. The real service answers 422, which the
             * client maps to an exception; a double that quietly wiped its index
             * instead would let a product ship the sync bug this guard exists
             * for - and the test would pass.
             */
            throw new Exceptions\AllProvidersFailedException(
                'No slugs were listed. Pass confirmEmpty to retire everything on purpose.',
                ['refusing_to_empty_the_index' => 'the index would have been wiped'],
            );
        }

        $before = count($this->chunks);

        foreach ($this->chunks as $key => $chunk) {
            if (! in_array($chunk['slug'], $keep, true)) {
                unset($this->chunks[$key]);
            }
        }

        return $before - count($this->chunks);
    }

    public function retrieve(
        string $query,
        ?string $audience = null,
        int $limit = 5,
        ?float $floor = null,
        string $task = 'knowledge.embed.query',
        ?float $budgetSeconds = null,
    ): array {
        $this->retrievals[] = ['query' => $query, 'audience' => $audience];

        // ⚠ The hard filter, first, exactly as the service applies it.
        $candidates = array_filter(
            $this->chunks,
            fn (array $c) => $audience === null ? $c['audience'] === null : ($c['audience'] === null || $c['audience'] === $audience),
        );

        $usable = array_filter($candidates, fn (array $c) => $c['model'] === $this->model);
        $stale = count($candidates) - count($usable);

        if ($candidates !== [] && $usable === []) {
            return [
                'matches' => [], 'model' => $this->model, 'scored' => 0,
                'skipped_stale_model' => $stale, 'index_stale' => true,
            ];
        }

        $tokens = $this->tokenise($query);
        $matches = [];

        foreach ($usable as $chunk) {
            $chunkTokens = $this->tokenise($chunk['text']);
            $shared = count(array_intersect($tokens, $chunkTokens));

            if ($shared === 0) {
                continue;
            }

            $matches[] = [
                'slug'     => $chunk['slug'],
                'audience' => $chunk['audience'],
                'ord'      => $chunk['ord'],
                'score'    => round($shared / max(1, count($tokens)), 6),
                'text'     => $chunk['text'],
            ];
        }

        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'matches'             => array_slice($matches, 0, max(1, $limit)),
            'model'               => $this->model,
            'scored'              => count($usable),
            'skipped_stale_model' => $stale,
            'index_stale'         => false,
        ];
    }

    public function status(): array
    {
        $stale = array_filter($this->chunks, fn (array $c) => $c['model'] !== $this->model);

        return [
            'product'       => 'fake',
            'chunks'        => count($this->chunks),
            'embedded'      => count($this->chunks),
            'current_model' => $this->model,
            'by_model'      => array_count_values(array_map(fn (array $c) => (string) $c['model'], $this->chunks)),
            'stale_chunks'  => count($stale),
            'audiences'     => array_values(array_unique(array_map(fn (array $c) => $c['audience'], $this->chunks))),
        ];
    }

    /** @return array<int, string> */
    private function tokenise(string $text): array
    {
        preg_match_all('/[a-z0-9]{3,}/i', mb_strtolower($text), $m);

        return array_values(array_unique($m[0] ?? []));
    }
}
