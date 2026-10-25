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

    /** @var array<string, array<string, mixed>> slug => entry, as the service stores it */
    public array $entryRows = [];

    public function syncEntries(array $entries, string $task = 'knowledge.embed.document'): array
    {
        $synced = 0;
        $unchanged = 0;

        foreach ($entries as $e) {
            $row = [
                'slug' => (string) $e['slug'],
                'status' => (string) ($e['status'] ?? 'published'),
                'audiences' => isset($e['audiences']) && $e['audiences'] !== [] ? array_values($e['audiences']) : null,
                'expires_at' => $e['expires_at'] ?? null,
                'question' => (string) $e['question'],
                'aliases' => array_values((array) ($e['aliases'] ?? [])),
                'description' => $e['description'] ?? null,
                'answer' => (string) $e['answer'],
                'extra' => (array) ($e['extra'] ?? []),
            ];

            if (($this->entryRows[$row['slug']] ?? null) == $row) {
                $unchanged++;
            } else {
                $this->entryRows[$row['slug']] = $row;
                $synced++;
            }
        }

        return ['synced' => $synced, 'unchanged' => $unchanged];
    }

    public function retireEntries(array $keepSlugs, bool $confirmEmpty = false): int
    {
        if ($keepSlugs === [] && ! $confirmEmpty) {
            throw new \RuntimeException('ai-service answered 422 refusing_to_empty_the_knowledge');
        }

        $before = count($this->entryRows);
        $this->entryRows = array_intersect_key($this->entryRows, array_flip($keepSlugs));

        return $before - count($this->entryRows);
    }

    /**
     * ⚠ THE SAME FACETS AND LEXICAL SCORING AS THE SERVICE (no semantic half: there are no vectors),
     * so a product's retrieval test cannot pass on an expired or wrong-audience entry.
     */
    public function search(string $query, ?string $audience, int $limit = 5): array
    {
        $tokens = self::tokens($query);
        $out = [];

        foreach ($this->servable($audience) as $row) {
            $aliases = [];
            foreach ($row['aliases'] as $alias) {
                $aliases = array_merge($aliases, self::tokens((string) $alias));
            }
            $score = 0.60 * self::overlap($tokens, self::tokens($row['question']))
                + 0.30 * self::overlap($tokens, $aliases)
                + 0.10 * self::overlap($tokens, self::tokens((string) $row['description']));

            if ($score >= 0.12) {
                $out[] = ['entry' => $row, 'score' => round($score, 6), 'lexical' => round($score, 6), 'semantic' => 0.0];
            }
        }

        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, max(1, $limit));
    }

    public function entries(?string $audience = null, bool $all = false, int $page = 1, int $perPage = 50): array
    {
        $rows = $all ? array_values($this->entryRows) : $this->servable($audience);

        return ['entries' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'meta' => [
            'page' => $page, 'last_page' => max(1, (int) ceil(count($rows) / $perPage)), 'total' => count($rows),
        ]];
    }

    public function entry(string $slug): ?array
    {
        return $this->entryRows[$slug] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function servable(?string $audience): array
    {
        return array_values(array_filter($this->entryRows, function (array $row) use ($audience) {
            if ($row['status'] !== 'published') {
                return false;
            }
            if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
                return false;
            }

            return $row['audiences'] === null || ($audience !== null && in_array($audience, $row['audiences'], true));
        }));
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        static $stop = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'am', 'do', 'does', 'did', 'can', 'could', 'will', 'would', 'should',
            'i', 'me', 'my', 'we', 'our', 'you', 'your', 'it', 'its', 'they', 'to', 'of', 'in', 'on', 'for', 'with', 'at', 'by', 'from', 'as',
            'and', 'or', 'but', 'if', 'then', 'than', 'that', 'this', 'these', 'how', 'what', 'when', 'where', 'who', 'why', 'which',
            'have', 'has', 'had', 'get', 'got', 'need', 'want', 'please'];
        $parts = preg_split('/[^a-z0-9]+/u', mb_strtolower(strip_tags($text))) ?: [];

        return array_values(array_filter($parts, fn ($t) => $t !== '' && strlen($t) > 1 && ! in_array($t, $stop, true)));
    }

    private static function overlap(array $asked, array $candidate): float
    {
        if ($asked === [] || $candidate === []) {
            return 0.0;
        }
        $set = array_flip($candidate);
        $hits = 0;
        foreach ($asked as $token) {
            if (isset($set[$token])) {
                $hits++;
                continue;
            }
            foreach ($candidate as $other) {
                if (strlen($token) > 4 && strlen($other) > 4
                    && (str_starts_with($other, substr($token, 0, -1)) || str_starts_with($token, substr($other, 0, -1)))) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits / count($asked);
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
